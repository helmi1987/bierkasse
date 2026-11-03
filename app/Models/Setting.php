<?php

namespace App\Models;

use App\Services\Audit;
use App\Services\Tenancy;
use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Key/value settings. Every value is JSON encoded and encrypted with APP_KEY.
 * Per tenant (tenant_id = current tenant) except the global keys in AppSettings::GLOBAL_KEYS
 * (tenant_id null: mail, maintenance mode, tenancy mode, ...). Without a current tenant (multi mode,
 * request without tenant context) tenant settings read as unset and cannot be written.
 */
class Setting extends Model
{
    protected $fillable = ['tenant_id', 'key', 'value'];

    /** Internal state, not a setting changed by a person. */
    private const NOT_AUDITED = ['stock_low_warned'];

    /** @var array<string, array<string, mixed>> cache per scope ("global" or the tenant id) */
    private static array $cache = [];

    public static function isGlobal(string $key): bool
    {
        return in_array($key, AppSettings::GLOBAL_KEYS, true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $scope = self::scope($key);
        if ($scope === null) {
            return $default;
        }
        self::loadCache($scope);

        return array_key_exists($key, self::$cache[$scope]) ? self::$cache[$scope][$key] : $default;
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
        $before = [];
        $after = [];
        $scopes = [];
        foreach ($values as $key => $value) {
            $scope = self::scope($key);
            if ($scope === null) {
                throw new \RuntimeException("No current tenant for setting \"{$key}\".");
            }
            self::loadCache($scope);
            $scopes[$key] = $scope;
            if (! in_array($key, self::NOT_AUDITED, true)) {
                $before += self::flatten($key, self::$cache[$scope][$key] ?? null);
                $after += self::flatten($key, $value);
            }
        }
        [$old, $new] = Audit::diff($before, $after);

        DB::transaction(function () use ($values, $scopes, $action, $old, $new) {
            foreach ($values as $key => $value) {
                static::query()->updateOrCreate(
                    ['tenant_id' => self::tenantIdOf($scopes[$key]), 'key' => $key],
                    ['value' => Crypt::encryptString(json_encode($value))],
                );
            }
            if ($new !== []) {
                Audit::log($action, null, $old, $new);
            }
        });

        foreach ($values as $key => $value) {
            self::$cache[$scopes[$key]][$key] = $value;
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
        $scope = self::scope($key);
        if ($scope === null) {
            return;
        }
        static::query()->where('tenant_id', self::tenantIdOf($scope))->where('key', $key)->delete();
        unset(self::$cache[$scope][$key]);
    }

    /** Stored row of a key in the current scope (tests, migrations). */
    public static function row(string $key): ?self
    {
        $scope = self::scope($key);

        return $scope === null ? null : static::query()->where('tenant_id', self::tenantIdOf($scope))->where('key', $key)->first();
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /** "global" for global keys, the current tenant id for the others, null without a current tenant. */
    private static function scope(string $key): ?string
    {
        if (self::isGlobal($key)) {
            return 'global';
        }
        $id = Tenancy::currentId();

        return $id === null ? null : (string) $id;
    }

    private static function tenantIdOf(string $scope): ?int
    {
        return $scope === 'global' ? null : (int) $scope;
    }

    private static function loadCache(string $scope): void
    {
        if (isset(self::$cache[$scope])) {
            return;
        }

        self::$cache[$scope] = [];

        $tenantId = self::tenantIdOf($scope);
        $query = static::query()->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId));
        foreach ($query->get() as $row) {
            try {
                self::$cache[$scope][$row->key] = json_decode(Crypt::decryptString((string) $row->value), true);
            } catch (Throwable) {
                // Undecryptable (e.g. APP_KEY changed) - treat as unset.
            }
        }
    }
}
