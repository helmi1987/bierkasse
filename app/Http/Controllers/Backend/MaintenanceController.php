<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\DatabaseBackup;
use App\Support\AppSettings;
use App\Support\SystemInfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin maintenance page: system info, cache/rate limit/optimization clearing,
 * database migrations, database backups and the maintenance mode for users.
 */
class MaintenanceController extends Controller
{
    public function index(): View
    {
        $info = SystemInfo::overview();

        return view('backend.maintenance', [
            'info' => $info,
            'diskFree' => SystemInfo::bytes($info['disk_free']),
            'pending' => $info['pending'] ?? [],
            'maintenance' => AppSettings::maintenanceMode(),
            'maintenanceMessage' => AppSettings::maintenanceMessage(),
            'backups' => DatabaseBackup::list(),
        ]);
    }

    public function clearCache(Request $request): RedirectResponse
    {
        try {
            $ok = Artisan::call('cache:clear') === 0;
            // Rate limits live in their own store (config cache.limiter); clear them as well.
            $this->flushLimiter();
        } catch (Throwable $e) {
            return $this->failed($request, 'cache:clear', $e);
        }
        $this->log($request, 'cache cleared', ['ok' => $ok]);

        return back()->with($ok ? 'status' : 'error', __($ok ? 'maintenance.cache_done' : 'maintenance.cache_failed'));
    }

    public function clearLimits(Request $request): RedirectResponse
    {
        try {
            $this->flushLimiter();
        } catch (Throwable $e) {
            return $this->failed($request, 'rate limits', $e);
        }
        $this->log($request, 'rate limits cleared');

        return back()->with('status', __('maintenance.limits_done'));
    }

    public function clearOptimize(Request $request): RedirectResponse
    {
        // Deletes the cached config/routes/events/views files only; this request keeps
        // its loaded configuration and routes, the next request rebuilds from source.
        try {
            Artisan::call('optimize:clear');
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            return $this->failed($request, 'optimize:clear', $e);
        }
        $this->log($request, 'optimization cache cleared');

        return back()->with('status', __('maintenance.optimize_done'))->with('maintenance_output', $output);
    }

    public function migrate(Request $request): RedirectResponse
    {
        try {
            $code = Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            return $this->failed($request, 'migrate', $e);
        }
        $this->log($request, 'database migrated', ['exit' => $code]);

        return back()
            ->with($code === 0 ? 'status' : 'error', __($code === 0 ? 'maintenance.migrate_done' : 'maintenance.migrate_failed'))
            ->with('maintenance_output', $output);
    }

    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'maintenance_message' => ['nullable', 'string', 'max:500'],
        ]);
        $on = $request->boolean('maintenance');

        Setting::putMany([
            AppSettings::MAINTENANCE => $on,
            AppSettings::MAINTENANCE_MESSAGE => trim((string) ($data['maintenance_message'] ?? '')),
        ]);
        $this->log($request, $on ? 'maintenance mode on' : 'maintenance mode off');

        return back()->with('status', __($on ? 'maintenance.mode_on_done' : 'maintenance.mode_off_done'));
    }

    /**
     * Streams a fresh gzip dump to the browser without holding it in memory.
     */
    public function backup(Request $request): StreamedResponse|RedirectResponse
    {
        try {
            (new DatabaseBackup)->assertSupported();
        } catch (Throwable $e) {
            return $this->failed($request, 'backup download', $e);
        }
        $name = DatabaseBackup::filename();
        $admin = $request->user()?->username;

        return response()->streamDownload(function () use ($name, $admin) {
            $gzip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            try {
                $stats = (new DatabaseBackup)->dump(function (string $chunk) use ($gzip) {
                    $out = deflate_add($gzip, $chunk, ZLIB_NO_FLUSH);
                    if ($out !== '') {
                        echo $out;
                        flush();
                    }
                });
                echo deflate_add($gzip, '', ZLIB_FINISH);
                Log::info('Maintenance: backup downloaded', ['admin' => $admin, 'file' => $name] + $stats);
            } catch (Throwable $e) {
                // Headers are already sent; the download ends without the end marker.
                report($e);
                Log::info('Maintenance: backup download failed', ['admin' => $admin, 'error' => $e->getMessage()]);
            }
        }, $name, [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function backupFile(Request $request, string $backup): BinaryFileResponse
    {
        $name = $this->backupName($backup);
        $this->log($request, 'stored backup downloaded', ['file' => $name]);

        return response()->download(DatabaseBackup::directory().'/'.$name, $name, [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function backupDelete(Request $request, string $backup): RedirectResponse
    {
        $name = $this->backupName($backup);
        if (! @unlink(DatabaseBackup::directory().'/'.$name)) {
            $this->log($request, 'stored backup delete failed', ['file' => $name]);

            return back()->with('error', __('maintenance.backup_delete_failed'));
        }
        $this->log($request, 'stored backup deleted', ['file' => $name]);

        return back()->with('status', __('maintenance.backup_deleted', ['name' => $name]));
    }

    /**
     * Only plain file names written by db:backup in the backup directory (no paths).
     */
    private function backupName(string $backup): string
    {
        $name = $backup.'.sql.gz';
        abort_unless(DatabaseBackup::isBackupName($name) && is_file(DatabaseBackup::directory().'/'.$name), 404);

        return $name;
    }

    private function flushLimiter(): void
    {
        // Own store "limiter" (see config/cache.php); if CACHE_LIMITER_STORE points to the
        // application cache, this clears that whole store. A missing cache directory
        // (nothing limited yet) makes flush() return false, which is fine.
        $store = (string) config('cache.limiter');
        Cache::store($store !== '' ? $store : null)->flush();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(Request $request, string $action, array $context = []): void
    {
        Log::info('Maintenance: '.$action, ['admin' => $request->user()?->username] + $context);
    }

    private function failed(Request $request, string $action, Throwable $e): RedirectResponse
    {
        report($e);
        $this->log($request, $action.' failed', ['error' => $e->getMessage()]);

        return back()->with('error', __('maintenance.failed', ['error' => Str::limit($e->getMessage(), 300)]));
    }
}
