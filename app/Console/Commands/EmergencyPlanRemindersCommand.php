<?php

namespace App\Console\Commands;

use App\Services\EmergencyPlan;
use Illuminate\Console\Command;

/** Offline emergency plan (1.40.0): reminder mail when the printed list is out of date (daily via the scheduler). */
class EmergencyPlanRemindersCommand extends Command
{
    protected $signature = 'emergency-plan:reminders
        {--dry-run : Only show whether a reminder is due}';

    protected $description = 'Remind the chosen admins/kassiers to print the offline emergency plan again';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $r = EmergencyPlan::remind($dryRun);

        $this->line(sprintf(
            '%sdifference %d, %s, %d recipient(s), %d sent',
            $dryRun ? '[dry-run] ' : '',
            $r['count'], $r['reason'], $r['recipients'], $r['sent'],
        ));

        return self::SUCCESS;
    }
}
