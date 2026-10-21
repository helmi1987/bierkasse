<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--path= : Target file or directory (default storage/app/backups)}
        {--keep= : Keep only the newest N backups in the target directory}';

    protected $description = 'Write a gzip SQL dump of the database (pure PHP, no mysqldump needed)';

    public function handle(): int
    {
        $keep = $this->option('keep');
        if ($keep !== null && (! ctype_digit((string) $keep) || (int) $keep < 1)) {
            $this->error('--keep must be a positive number.');

            return self::FAILURE;
        }

        $target = (string) ($this->option('path') ?: DatabaseBackup::directory());
        // A directory (existing or ending with "/") gets the standard file name.
        $file = is_dir($target) || str_ends_with($target, '/') || $this->option('path') === null
            ? rtrim($target, '/').'/'.DatabaseBackup::filename()
            : $target;
        $directory = dirname($file);
        $part = $file.'.part';

        // Logged before the dump, so the backup contains its own entry.
        Audit::try('backup.create', null, [], ['file' => basename($file)], null, 'cli');

        $gz = null;
        try {
            if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Cannot create directory {$directory}.");
            }
            $gz = @gzopen($part, 'wb6');
            if ($gz === false) {
                throw new \RuntimeException("Cannot write {$part}.");
            }
            $stats = (new DatabaseBackup)->dump(function (string $chunk) use ($gz) {
                if (gzwrite($gz, $chunk) === false) {
                    throw new \RuntimeException('Write failed (disk full?).');
                }
            });
            if (! gzclose($gz)) {
                $gz = null;
                throw new \RuntimeException('Write failed (disk full?).');
            }
            $gz = null;
            if (! rename($part, $file)) {
                throw new \RuntimeException("Cannot rename {$part}.");
            }
        } catch (Throwable $e) {
            if (is_resource($gz)) {
                gzclose($gz);
            }
            @unlink($part);
            Log::error('Database backup failed', ['error' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $size = (int) filesize($file);
        Log::info('Database backup created', ['file' => basename($file)] + $stats + ['size' => $size]);
        $this->info("Backup written: {$file}");
        $this->line("Tables: {$stats['tables']}, rows: {$stats['rows']}, size: ".Number::fileSize($size, 1)." ({$size} bytes)");

        if ($keep !== null) {
            foreach (DatabaseBackup::prune((int) $keep, $directory) as $name) {
                $this->line("Deleted old backup: {$name}");
            }
        }

        return self::SUCCESS;
    }
}
