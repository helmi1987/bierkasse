<?php

namespace App\Console\Commands;

use App\Services\Disposals;
use Illuminate\Console\Command;

/**
 * Books the "Entsorgungsbonus" of all disposals whose withdrawal window is over (scheduled every minute;
 * page views catch up when the cron does not run, see BookDueDisposals).
 */
class DisposalsBookCommand extends Command
{
    protected $signature = 'disposals:book';

    protected $description = 'Book the bonus of disposals whose withdrawal window is over';

    public function handle(): int
    {
        $booked = Disposals::bookDue();
        $this->info(sprintf('%d disposal bonus(es) booked.', $booked));

        return self::SUCCESS;
    }
}
