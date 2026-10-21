<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseRestoreCommand extends Command
{
    protected $signature = 'db:restore
        {file : Dump written by db:backup (.sql.gz or .sql)}
        {--connection= : Database connection (default: the default connection)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Restore a db:backup dump via PDO (no mysql client needed); overwrites the tables in the dump';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $connection = $this->option('connection') ?: null;

        try {
            $db = DB::connection($connection);
            $target = $db->getName().' ('.$db->getDriverName().', database '.$db->getDatabaseName().')';
        } catch (Throwable $e) {
            $this->error('Connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Restore {$file} into {$target}? All tables contained in the dump are dropped and replaced.")) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        try {
            $count = (new DatabaseBackup($connection))->restore($file);
        } catch (Throwable $e) {
            Log::error('Database restore failed', ['file' => basename($file), 'error' => $e->getMessage()]);
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('Database restored', ['file' => basename($file), 'statements' => $count]);
        // The audit log (with its hash chain) is part of the dump; the restore itself is appended.
        if ($db->getName() === DB::connection()->getName() && Schema::hasTable('audit_logs')) {
            Audit::try('backup.restore', null, [], ['file' => basename($file), 'statements' => $count], null, 'cli');
        }
        $this->info("Restored {$file} into {$target}: {$count} statements.");

        return self::SUCCESS;
    }
}
