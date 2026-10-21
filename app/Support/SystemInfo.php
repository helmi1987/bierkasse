<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only facts for the admin maintenance page. Never runs shell commands
 * (shared hosting); the git version is read from the files in .git.
 */
class SystemInfo
{
    /**
     * Current commit from .git (HEAD, loose ref or packed-refs).
     *
     * @return array{hash:string,branch:?string,date:?int,date_source:?string}|null
     */
    public static function gitVersion(?string $base = null): ?array
    {
        try {
            $gitDir = self::gitDir($base ?? base_path());
            if ($gitDir === null) {
                return null;
            }

            $head = trim((string) @file_get_contents($gitDir.'/HEAD'));
            if ($head === '') {
                return null;
            }

            $branch = null;
            $refFile = null;
            if (str_starts_with($head, 'ref:')) {
                $ref = trim(substr($head, 4));
                if (! preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) || str_contains($ref, '..')) {
                    return null;
                }
                $branch = preg_replace('#^refs/heads/#', '', $ref);
                $hash = null;
                if (is_file($gitDir.'/'.$ref)) {
                    $hash = trim((string) @file_get_contents($gitDir.'/'.$ref));
                    $refFile = $gitDir.'/'.$ref;
                } elseif (is_file($gitDir.'/packed-refs')) {
                    foreach (file($gitDir.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                        if (preg_match('/^([0-9a-f]{40,64}) (.+)$/', $line, $m) && $m[2] === $ref) {
                            $hash = $m[1];
                            $refFile = $gitDir.'/packed-refs';
                            break;
                        }
                    }
                }
            } else {
                $hash = $head;
                $refFile = $gitDir.'/HEAD';
            }

            if (! is_string($hash) || ! preg_match('/^[0-9a-f]{40,64}$/', $hash)) {
                return null;
            }

            // Commit date from the loose object; packed objects are not parsed, then the
            // modification time of the ref file is used (time of the last update/deploy).
            $date = self::commitDate($gitDir, $hash);
            $source = $date !== null ? 'commit' : null;
            if ($date === null && $refFile !== null && ($mtime = @filemtime($refFile)) !== false) {
                $date = $mtime;
                $source = 'file';
            }

            return ['hash' => substr($hash, 0, 7), 'branch' => $branch, 'date' => $date, 'date_source' => $source];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Migrations that exist as files but have not run yet.
     *
     * @return list<string>
     */
    public static function pendingMigrations(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_diff(array_keys($files), $ran));
    }

    /**
     * @return array<string, mixed>
     */
    public static function overview(): array
    {
        $dbOk = false;
        $dbError = null;
        $driver = (string) config('database.connections.'.config('database.default').'.driver', '');
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        $pending = null;
        if ($dbOk) {
            try {
                $pending = self::pendingMigrations();
            } catch (Throwable) {
                $pending = null;
            }
        }

        $free = function_exists('disk_free_space') ? @disk_free_space(storage_path()) : false;

        return [
            'git' => self::gitVersion(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'db_driver' => $driver,
            'db_ok' => $dbOk,
            'db_error' => $dbError,
            'pending' => $pending,
            'cache' => (string) config('cache.default'),
            'limiter' => (string) config('cache.limiter'),
            'session' => (string) config('session.driver'),
            'queue' => (string) config('queue.default'),
            'mail' => AppSettings::mail()['transport'],
            'storage_writable' => is_writable(storage_path()),
            'bootstrap_writable' => is_writable(base_path('bootstrap/cache')),
            'env' => (string) app()->environment(),
            'debug' => (bool) config('app.debug'),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'disk_free' => is_float($free) || is_int($free) ? (int) $free : null,
        ];
    }

    public static function bytes(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : 1, '.', "'").' '.$units[$i];
    }

    private static function gitDir(string $base): ?string
    {
        $path = rtrim($base, '/').'/.git';
        if (is_dir($path)) {
            return $path;
        }
        // Worktree/submodule: ".git" is a file "gitdir: <path>".
        if (is_file($path) && preg_match('/^gitdir:\s*(.+)$/m', (string) @file_get_contents($path), $m)) {
            $dir = trim($m[1]);
            if (! str_starts_with($dir, '/')) {
                $dir = rtrim($base, '/').'/'.$dir;
            }

            return is_dir($dir) ? $dir : null;
        }

        return null;
    }

    private static function commitDate(string $gitDir, string $hash): ?int
    {
        $file = $gitDir.'/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2);
        if (! is_file($file) || ! function_exists('zlib_decode')) {
            return null;
        }
        $raw = @zlib_decode((string) @file_get_contents($file, false, null, 0, 65536));
        if (! is_string($raw) || ! str_starts_with($raw, 'commit ')) {
            return null;
        }

        return preg_match('/^committer .* (\d+) [+-]\d{4}$/m', $raw, $m) ? (int) $m[1] : null;
    }
}
