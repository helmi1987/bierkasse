<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Append-only audit log with a SHA-256 hash chain (decision 2026-10-22: never deleted).
 *
 * hash = sha256(canonical JSON of [prev_hash, created_at, actor_ref, actor_name, action,
 * subject_type, subject_id, old_values, new_values, ip, user_agent]). The head of the chain
 * (last hash, number of entries) is kept in "audit_chain" and locked while appending, so
 * concurrent writers never fork the chain. Call log() inside the DB transaction of the change:
 * either both are stored or none.
 *
 * Secrets are never stored: keys that look like secrets are replaced by "***changed***".
 */
class Audit
{
    public const MASK = '***changed***';

    /** Setting/attribute keys whose values are never written to the log. */
    private const SECRET_KEYS = ['registration_flyer_code', 'totp_recovery_codes', 'remember_token'];

    private const SECRET_PATTERN = '/password|secret|token|totp_secret|recovery/i';

    /**
     * @param  array<string, mixed>  $old  Only changed fields
     * @param  array<string, mixed>  $new  Only changed fields
     * @param  User|null  $actor  Default: the logged-in user (null = system / guest)
     */
    public static function log(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?User $actor = null,
        ?string $actorName = null,
    ): void {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $entry = [
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'actor_id' => $actor?->getKey(),
            'actor_ref' => $actor?->getKey(),
            'actor_name' => $actor !== null ? Str::limit((string) $actor->username, 100, '') : ($actorName !== null ? Str::limit($actorName, 100, '') : null),
            'action' => Str::limit($action, 64, ''),
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (int) $subject->getKey() : null,
            'old_values' => self::encode(self::mask($old, false)),
            'new_values' => self::encode(self::mask($new, true)),
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 255, '') ?: null : 'cli',
        ];

        DB::transaction(function () use ($entry) {
            $head = DB::table('audit_chain')->where('id', 1)->lockForUpdate()->first();
            if ($head === null) {
                DB::table('audit_chain')->insert(['id' => 1, 'last_hash' => '', 'entries' => 0]);
                $head = DB::table('audit_chain')->where('id', 1)->lockForUpdate()->first();
            }
            $entry['prev_hash'] = (string) $head->last_hash;
            $entry['hash'] = self::hash($entry);
            DB::table('audit_logs')->insert($entry);
            DB::table('audit_chain')->where('id', 1)->update(['last_hash' => $entry['hash'], 'entries' => (int) $head->entries + 1]);
        });
    }

    /**
     * Same as log(), but never throws (for places where the log must not break the response,
     * e.g. a failed login). Errors are reported.
     */
    public static function try(string $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, ?string $actorName = null): void
    {
        try {
            self::log($action, $subject, $old, $new, $actor, $actorName);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Changed fields of two attribute arrays.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ($a !== $b) {
                $old[$key] = $a;
                $new[$key] = $b;
            }
        }

        return [$old, $new];
    }

    /**
     * Saves a model and logs its changed fields in the same DB transaction ($always: log even
     * without changes, e.g. a password set to the same value).
     */
    public static function save(string $action, Model $model, bool $always = false): void
    {
        [$old, $new] = self::dirty($model);
        DB::transaction(function () use ($action, $model, $old, $new, $always) {
            $model->save();
            if ($always || $new !== []) {
                self::log($action, $model, $old, $new);
            }
        });
    }

    /**
     * Pending changes of a model (before save), without timestamps. Secrets are masked by log().
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function dirty(Model $model): array
    {
        $new = array_diff_key($model->getDirty(), array_flip(['created_at', 'updated_at']));
        $old = $model->exists ? array_intersect_key($model->getRawOriginal(), $new) : [];

        return [$old, $new];
    }

    /**
     * Checks the whole chain. Returns null when intact, else the first broken entry.
     *
     * @return array{id: int|null, reason: string}|null
     */
    public static function verify(): ?array
    {
        $prev = '';
        $count = 0;
        $broken = null;

        foreach (DB::table('audit_logs')->lazyById(500) as $row) {
            $entry = (array) $row;
            if ((string) $entry['prev_hash'] !== $prev) {
                $broken = ['id' => (int) $entry['id'], 'reason' => 'gap'];
                break;
            }
            if (! hash_equals(self::hash($entry), (string) $entry['hash'])) {
                $broken = ['id' => (int) $entry['id'], 'reason' => 'changed'];
                break;
            }
            $prev = (string) $entry['hash'];
            $count++;
        }

        if ($broken !== null) {
            return $broken;
        }

        $head = DB::table('audit_chain')->where('id', 1)->first();
        if ($head !== null && ((string) $head->last_hash !== $prev || (int) $head->entries !== $count)) {
            return ['id' => null, 'reason' => 'tail'];
        }

        return null;
    }

    /** @param  array<string, mixed>  $entry */
    private static function hash(array $entry): string
    {
        $int = fn ($v) => $v === null ? null : (int) $v;
        $str = fn ($v) => $v === null ? null : (string) $v;

        return hash('sha256', json_encode([
            (string) $entry['prev_hash'],
            (string) $entry['created_at'],
            $int($entry['actor_ref']),
            $str($entry['actor_name']),
            (string) $entry['action'],
            $str($entry['subject_type']),
            $int($entry['subject_id']),
            $str($entry['old_values']),
            $str($entry['new_values']),
            $str($entry['ip']),
            $str($entry['user_agent']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param  array<string, mixed>  $values */
    private static function encode(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return json_encode(self::canonical($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (! is_array($value)) {
            return is_object($value) ? (string) json_encode($value) : $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($v) => self::canonical($v), $value);
    }

    /**
     * Replaces secret values (also nested, e.g. mail.smtp_password).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function mask(array $values, bool $isNew): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && (in_array($key, self::SECRET_KEYS, true) || preg_match(self::SECRET_PATTERN, $key))) {
                $values[$key] = $isNew ? self::MASK : '***';
            } elseif (is_array($value)) {
                $values[$key] = self::mask($value, $isNew);
            }
        }

        return $values;
    }
}
