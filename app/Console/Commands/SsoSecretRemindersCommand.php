<?php

namespace App\Console\Commands;

use App\Services\SsoSecretReminders;
use Illuminate\Console\Command;

class SsoSecretRemindersCommand extends Command
{
    protected $signature = 'sso:secret-reminders
        {--dry-run : Only list what would be sent}';

    protected $description = 'Send client-secret expiry reminders for SSO providers to the admins';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = SsoSecretReminders::run($dryRun);

        foreach ($results as $row) {
            $this->line(sprintf(
                '%s%s: stage %d (%d days left), %d recipient(s)',
                $dryRun ? '[dry-run] ' : '',
                $row['slug'], $row['stage'], $row['days_left'], $row['recipients'],
            ));
        }
        $this->info(sprintf('%d reminder(s) %s.', count($results), $dryRun ? 'due' : 'processed'));

        return self::SUCCESS;
    }
}
