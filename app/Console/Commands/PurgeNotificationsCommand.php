<?php

namespace App\Console\Commands;

use App\Actions\Notifications\PurgeNotifications;
use Illuminate\Console\Command;

class PurgeNotificationsCommand extends Command
{
    protected $signature = 'kintai:notifications:purge';

    protected $description = 'Delete read notifications older than the retention period';

    public function handle(PurgeNotifications $purge): int
    {
        $deleted = $purge->handle();

        $this->components->info("{$deleted} read notification(s) older than ".PurgeNotifications::retentionDays().' days deleted.');

        return self::SUCCESS;
    }
}
