<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
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

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::loadCache();

        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::loadCache();

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString(json_encode($value))],
        );

        self::$cache[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::put($key, $value);
        }
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
