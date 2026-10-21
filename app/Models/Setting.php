<?php

namespace App\Models;

use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Key/value settings. Every value is JSON encoded and encrypted with APP_KEY.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** Internal state, not a setting changed by a person. */
    private const NOT_AUDITED = ['stock_low_warned'];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::loadCache();

        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function put(string $key, mixed $value, string $action = 'settings.update'): void
    {
        self::putMany([$key => $value], $action);
    }

    /**
     * Saves the values and writes one audit entry with the changed keys (secrets masked),
     * in one DB transaction.
     *
     * @param  array<string, mixed>  $values
     */
    public static function putMany(array $values, string $action = 'settings.update'): void
    {
        self::loadCache();
        $before = [];
        $after = [];
        foreach ($values as $key => $value) {
            if (! in_array($key, self::NOT_AUDITED, true)) {
                $before += self::flatten($key, self::$cache[$key] ?? null);
                $after += self::flatten($key, $value);
            }
        }
        [$old, $new] = Audit::diff($before, $after);

        DB::transaction(function () use ($values, $action, $old, $new) {
            foreach ($values as $key => $value) {
                static::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => Crypt::encryptString(json_encode($value))],
                );
            }
            if ($new !== []) {
                Audit::log($action, null, $old, $new);
            }
        });

        foreach ($values as $key => $value) {
            self::$cache[$key] = $value;
        }
    }

    /**
     * Nested settings (e.g. "mail") as "mail.smtp_host" => value, so only changed sub-keys are logged.
     *
     * @return array<string, mixed>
     */
    private static function flatten(string $key, mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [$key => $value];
        }
        $flat = [];
        foreach ($value as $sub => $v) {
            $flat[$key.'.'.$sub] = $v;
        }

        return $flat;
    }

    public static function forget(string $key): void
    {
        static::query()->whereKey($key)->delete();
        if (self::$cache !== null) {
            unset(self::$cache[$key]);
        }
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function loadCache(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        foreach (static::query()->get() as $row) {
            try {
                self::$cache[$row->key] = json_decode(Crypt::decryptString((string) $row->value), true);
            } catch (Throwable) {
                // Undecryptable (e.g. APP_KEY changed) - treat as unset.
            }
        }
    }
}
