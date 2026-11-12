<?php

namespace App\Console\Commands;

use App\Actions\Notifications\PurgeNotifications;
use App\Actions\Push\PurgeInactivePushTargets;
use Illuminate\Console\Command;

class PurgeNotificationsCommand extends Command
{
    protected $signature = 'kintai:notifications:purge';

    protected $description = 'Delete read notifications older than the retention period, and push subscriptions and tokens unused for 180 days';

    public function handle(PurgeNotifications $purge, PurgeInactivePushTargets $pushTargets): int
    {
        $deleted = $purge->handle();

        $this->components->info("{$deleted} read notification(s) older than ".PurgeNotifications::retentionDays().' days deleted.');

        $inactive = $pushTargets->handle();

        $this->components->info("{$inactive['subscriptions']} web push subscription(s) and {$inactive['tokens']} device token(s) unused for ".PurgeInactivePushTargets::IDLE_DAYS.' days deleted.');

        return self::SUCCESS;
    }
}
