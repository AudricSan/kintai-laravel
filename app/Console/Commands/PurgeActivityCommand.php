<?php

namespace App\Console\Commands;

use App\Actions\Activity\PurgeActivityEntries;
use Illuminate\Console\Command;

class PurgeActivityCommand extends Command
{
    protected $signature = 'kintai:activity:purge';

    protected $description = 'Delete activity log entries older than the retention period (0 = keep everything)';

    public function handle(PurgeActivityEntries $purge): int
    {
        $days = PurgeActivityEntries::retentionDays();

        if ($days === 0) {
            $this->components->info('Activity log retention is unlimited: nothing to delete.');

            return self::SUCCESS;
        }

        $deleted = $purge->automatic();

        $this->components->info("{$deleted} activity log entrie(s) older than {$days} days deleted.");

        return self::SUCCESS;
    }
}
