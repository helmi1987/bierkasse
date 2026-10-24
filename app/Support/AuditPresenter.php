<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\Feedback;
use App\Models\GuestSale;
use App\Models\Invitation;
use App\Models\QrCode;
use App\Models\RegistrationCode;
use App\Models\SsoProvider;
use App\Models\StockCorrection;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Human-readable display of audit log entries (labels, subject names, formatted values).
 * Display only: the stored entries, the hash chain and the CSV export are not affected.
 *
 * Build it once per page with for(): subjects and referenced users are loaded in a few
 * batched queries (one per subject type plus one for users), never per row.
 */
class AuditPresenter
{
    /** Value keys holding Rappen (int or list of int). */
    private const MONEY_KEYS = [
        'amount', 'unit_price', 'beer_price', 'default_limit', 'overdraft_limit',
        'deposit_suggestions', 'deposit_suggestion_default',
    ];

    /** Value keys holding user ids (int or list of int). */
    private const USER_KEYS = ['user_id', 'created_by', 'confirmed_by', 'booked_by', 'used_by', 'recipients'];

    /** Value keys holding a date (Y-m-d) or a date and time. */
    private const DATE_KEYS = ['date', 'expires_at', 'confirmed_at', 'edited_at', 'left_at', 'used_at', 'last_login_at', 'discovered_at'];

    /** Subject types (class basename as stored) that are resolved to a name. */
    private const SUBJECT_CLASSES = [
        'CashEntry' => CashEntry::class,
        'Feedback' => Feedback::class,
        'GuestSale' => GuestSale::class,
        'Invitation' => Invitation::class,
        'QrCode' => QrCode::class,
        'RegistrationCode' => RegistrationCode::class,
        'SsoProvider' => SsoProvider::class,
        'StockCorrection' => StockCorrection::class,
        'Transaction' => Transaction::class,
        'User' => User::class,
        'UserIdentity' => UserIdentity::class,
    ];

    /** Shown first in the summary (most telling). */
    private const SUMMARY_FIRST = ['amount', 'quantity', 'beers', 'delta', 'counted', 'role', 'status', 'label', 'name', 'username'];

    /** Left out of the summary (visible on the detail page). */
    private const SUMMARY_SKIP = ['unit_price', 'confirmed_at', 'confirmed_by', 'edited_at', 'left_at'];

    /** Already part of the subject label of a booking. */
    private const SUMMARY_SKIP_TRANSACTION = ['type', 'user_id'];

    public const SUMMARY_MAX = 80;

    /**
     * @param  array<string, array<int, Model>>  $subjects  type => id => model
     * @param  array<int, User>  $users  id => user
     */
    private function __construct(private array $subjects, private array $users) {}

    /** @param  iterable<AuditLog>  $entries */
    public static function for(iterable $entries): self
    {
        $ids = [];
        $userIds = [];
        foreach ($entries as $e) {
            if ($e->subject_type !== null && $e->subject_id !== null && isset(self::SUBJECT_CLASSES[$e->subject_type])) {
                $ids[$e->subject_type][(int) $e->subject_id] = true;
            }
            foreach ([$e->oldValues(), $e->newValues()] as $values) {
                foreach (self::USER_KEYS as $key) {
                    foreach ((array) ($values[$key] ?? []) as $id) {
                        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                            $userIds[(int) $id] = true;
                        }
                    }
                }
            }
        }

        $subjects = [];
        foreach ($ids as $type => $set) {
            $class = self::SUBJECT_CLASSES[$type];
            $subjects[$type] = $class::query()->whereKey(array_keys($set))->get()->keyBy(fn (Model $m) => (int) $m->getKey())->all();
            if ($type === 'Transaction' || $type === 'UserIdentity') {
                foreach ($subjects[$type] as $m) {
                    $userIds[(int) $m->user_id] = true;
                }
            }
        }

        $users = $subjects['User'] ?? [];
        $missing = array_diff(array_keys($userIds), array_keys($users));
        if ($missing !== []) {
            $users += User::query()->whereKey($missing)->get()->keyBy(fn (User $u) => (int) $u->id)->all();
        }

        return new self($subjects, $users);
    }

    public static function actionLabel(string $action): string
    {
        return self::lookup('audit.actions', $action) ?? $action;
    }

    public static function prefixLabel(string $prefix): string
    {
        return self::lookup('audit.prefixes', $prefix) ?? $prefix;
    }

    public static function fieldLabel(string $key): string
    {
        return self::lookup('audit.fields', $key) ?? $key;
    }

    /** Whether a German label exists for the field (else the raw key is shown). */
    public static function hasFieldLabel(string $key): bool
    {
        return self::lookup('audit.fields', $key) !== null;
    }

    public static function typeLabel(string $type): string
    {
        return self::lookup('audit.subject_types', $type) ?? $type;
    }

    /** Name of the subject; "Type #id" when the subject is unknown or deleted; null without subject. */
    public function subjectLabel(AuditLog $e): ?string
    {
        if ($e->subject_type === null) {
            return null;
        }
        $fallback = self::typeLabel($e->subject_type).($e->subject_id !== null ? ' #'.$e->subject_id : '');
        $m = $this->subjects[$e->subject_type][(int) $e->subject_id] ?? null;
        if ($m === null) {
            return $fallback;
        }

        $label = match (true) {
            $m instanceof User => self::userName($m),
            $m instanceof Transaction => trim((self::lookup('ledger.type', (string) $m->type) ?? (string) $m->type).' '.Money::format((int) $m->amount)
                .' · '.$this->userLabel((int) $m->user_id)),
            $m instanceof GuestSale => self::typeLabel('GuestSale').' '.Money::format((int) $m->amount).($m->name ? ' · '.$m->name : ''),
            $m instanceof CashEntry => (self::lookup('cash.types', (string) $m->type) ?? self::typeLabel('CashEntry')).' '.Money::format((int) $m->amount),
            $m instanceof StockCorrection => self::typeLabel('StockCorrection').' '.sprintf('%+d', (int) $m->delta),
            $m instanceof QrCode => self::typeLabel('QrCode').' '.$m->displayName(),
            $m instanceof RegistrationCode, $m instanceof SsoProvider => self::typeLabel($e->subject_type).' '.($m->label ?? $m->name),
            $m instanceof UserIdentity => self::typeLabel('UserIdentity').' · '.$this->userLabel((int) $m->user_id),
            default => $fallback,
        };

        return trim($label) !== '' ? $label : $fallback;
    }

    /** Short summary of the changed values for the list, at most SUMMARY_MAX characters. */
    public function summary(AuditLog $e): string
    {
        $old = $e->oldValues();
        $new = $e->newValues();
        $skip = array_merge(self::SUMMARY_SKIP, $e->subject_type === 'Transaction' ? self::SUMMARY_SKIP_TRANSACTION : []);

        $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
        usort($keys, function (string $a, string $b) use ($keys) {
            $pa = array_search($a, self::SUMMARY_FIRST, true);
            $pb = array_search($b, self::SUMMARY_FIRST, true);
            $pa = $pa === false ? 100 + array_search($a, $keys, true) : $pa;
            $pb = $pb === false ? 100 + array_search($b, $keys, true) : $pb;

            return $pa <=> $pb;
        });

        $parts = [];
        foreach ($keys as $key) {
            $hasOld = array_key_exists($key, $old);
            $hasNew = array_key_exists($key, $new);
            // Context fields (same value before and after, e.g. user_id of a booking) say nothing.
            if (in_array($key, $skip, true) || ($hasOld && $hasNew && $old[$key] === $new[$key])) {
                continue;
            }
            if ($hasOld && $hasNew) {
                $parts[] = self::fieldLabel($key).': '.$this->value($key, $old[$key]).' → '.$this->value($key, $new[$key]);
            } elseif ($key === 'amount') {
                $parts[] = $this->value($key, $hasNew ? $new[$key] : $old[$key]);
            } else {
                $parts[] = self::fieldLabel($key).': '.$this->value($key, $hasNew ? $new[$key] : $old[$key]);
            }
        }

        return Str::limit(implode(', ', $parts), self::SUMMARY_MAX);
    }

    /** One value for display (plain text, escaped by Blade). */
    public function value(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '–';
        }
        if ($value === Audit::MASK) {
            return __('audit.masked');
        }
        if (is_bool($value)) {
            return $value ? __('audit.yes') : __('audit.no');
        }
        $base = self::baseKey($key);
        if (is_array($value)) {
            return $this->listValue($base, $value);
        }
        if (in_array($base, self::MONEY_KEYS, true) && is_numeric($value)) {
            return Money::format((int) $value);
        }
        if (in_array($base, self::USER_KEYS, true) && is_numeric($value)) {
            return $this->userLabel((int) $value);
        }
        if (in_array($base, self::DATE_KEYS, true) && is_string($value) && ($date = self::date($value)) !== null) {
            return $date;
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param  array<mixed>  $value */
    private function listValue(string $key, array $value): string
    {
        if ($value === []) {
            return '–';
        }
        if (array_is_list($value)) {
            $items = array_map(fn ($v) => is_array($v) ? $this->listValue($key, $v) : $this->value($key, $v), $value);
            // Lists of records (e.g. TWINT recipients) separated by ";".
            $nested = array_filter($value, 'is_array') !== [];

            return implode($nested ? '; ' : ', ', $items);
        }
        $items = [];
        foreach ($value as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $items[] = (is_string($k) ? $k.': ' : '').(is_array($v) ? $this->listValue($key, $v) : $this->value((string) $k, $v));
        }

        return $items === [] ? '–' : implode(', ', $items);
    }

    private function userLabel(int $id): string
    {
        $user = $this->users[$id] ?? null;

        return $user !== null ? self::userName($user) : __('audit.deleted_user', ['id' => $id]);
    }

    private static function userName(User $user): string
    {
        $name = $user->displayName();

        return $name !== (string) $user->username ? $user->username.' ('.$name.')' : (string) $user->username;
    }

    /**
     * "2026-10-24" → "24.10.2026"; date and time → "d.m.Y H:i" in the app timezone.
     * Assumption: times without offset were written by Laravel in the app timezone.
     */
    private static function date(string $value): ?string
    {
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return Carbon::createFromFormat('!Y-m-d', $value)->format('d.m.Y');
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
                return null;
            }

            return local_time(Carbon::parse($value, (string) config('app.timezone')));
        } catch (Throwable) {
            return null;
        }
    }

    /** "undo_minutes.consume" → "undo_minutes" (nested setting keys share the type of the parent). */
    private static function baseKey(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /** Entry of a translation map whose keys may contain dots (e.g. "user.role"). */
    private static function lookup(string $map, string $key): ?string
    {
        $labels = trans($map);
        $label = is_array($labels) ? ($labels[$key] ?? null) : null;

        return is_string($label) ? $label : null;
    }
}
