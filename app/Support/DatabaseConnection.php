<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tests database credentials without touching the active connection.
 *
 * Supported drivers: MySQL/MariaDB, PostgreSQL and SQLite (file path in "database").
 */
class DatabaseConnection
{
    public const DRIVERS = ['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'];

    public const DEFAULT_PORTS = ['mysql' => 3306, 'pgsql' => 5432, 'sqlite' => null];

    /**
     * @param  array{driver:string,host?:?string,port?:int|string|null,database:string,username?:?string,password?:?string}  $c
     * @return string|null Error message, or null on success.
     */
    public static function test(array $c): ?string
    {
        if (! array_key_exists($c['driver'], self::DRIVERS)) {
            return __('setup.error_driver');
        }

        if ($c['driver'] === 'sqlite' && ($error = self::prepareSqliteFile($c['database']))) {
            return $error;
        }

        Config::set('database.connections._probe', self::connectionConfig($c));

        try {
            DB::purge('_probe');
            DB::connection('_probe')->getPdo();
            DB::connection('_probe')->select('select 1');

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            DB::purge('_probe');
        }
    }

    /**
     * @param  array{driver:string,host?:?string,port?:int|string|null,database:string,username?:?string,password?:?string}  $c
     */
    public static function writeEnv(array $c): void
    {
        if ($c['driver'] === 'sqlite') {
            EnvEditor::set([
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $c['database'],
                'DB_HOST' => '',
                'DB_PORT' => '',
                'DB_USERNAME' => '',
                'DB_PASSWORD' => '',
            ]);

            return;
        }

        EnvEditor::set([
            'DB_CONNECTION' => $c['driver'],
            'DB_HOST' => (string) ($c['host'] ?? ''),
            'DB_PORT' => (string) ($c['port'] ?? ''),
            'DB_DATABASE' => $c['database'],
            'DB_USERNAME' => (string) ($c['username'] ?? ''),
            'DB_PASSWORD' => (string) ($c['password'] ?? ''),
        ]);
    }

    /**
     * Makes the given credentials the active default connection for this request.
     *
     * @param  array{driver:string,host?:?string,port?:int|string|null,database:string,username?:?string,password?:?string}  $c
     */
    public static function activate(array $c): void
    {
        Config::set('database.connections.'.$c['driver'], self::connectionConfig($c));
        Config::set('database.default', $c['driver']);
        DB::purge($c['driver']);
    }

    /**
     * @return array{driver:string,host:string,port:string,database:string,username:string}
     */
    public static function current(): array
    {
        $driver = (string) config('database.default');
        $conn = config('database.connections.'.$driver, []);

        return [
            'driver' => $driver,
            'host' => (string) ($conn['host'] ?? ''),
            'port' => (string) ($conn['port'] ?? ''),
            'database' => (string) ($conn['database'] ?? ''),
            'username' => (string) ($conn['username'] ?? ''),
        ];
    }

    /**
     * @param  array{driver:string,host?:?string,port?:int|string|null,database:string,username?:?string,password?:?string}  $c
     * @return array<string, mixed>
     */
    private static function connectionConfig(array $c): array
    {
        $base = (array) config('database.connections.'.$c['driver'], []);

        if ($c['driver'] === 'sqlite') {
            return array_merge($base, ['database' => $c['database']]);
        }

        return array_merge($base, [
            'host' => (string) ($c['host'] ?? ''),
            'port' => (int) ($c['port'] ?? 0),
            'database' => $c['database'],
            'username' => (string) ($c['username'] ?? ''),
            'password' => (string) ($c['password'] ?? ''),
        ]);
    }

    /**
     * Creates an empty SQLite file if it does not exist yet (the directory must exist).
     */
    private static function prepareSqliteFile(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            return __('setup.error_sqlite_path');
        }
        if (is_file($path)) {
            return is_writable($path) ? null : __('setup.error_sqlite_writable', ['path' => $path]);
        }
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            return __('setup.error_sqlite_dir', ['path' => dirname($path)]);
        }
        if (@touch($path) === false) {
            return __('setup.error_sqlite_writable', ['path' => $path]);
        }
        @chmod($path, 0660);

        return null;
    }
}
