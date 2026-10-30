<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\StorePhoto\Console;

use Illuminate\Console\Command;
use kintai\Bundles\Installed\StorePhoto\Services\PhotoRetentionService;

/** Supprime les photos de magasin au-delà de leur durée de rétention (voir PhotoRetentionService). Planifiée chaque nuit par StorePhotoBundle. */
final class RetentionCommand extends Command
{
    protected $signature = 'kintai:photo-retention';

    protected $description = 'Rétention des photos de magasin';

    public function handle(PhotoRetentionService $retention): int
    {
        $this->line(json_encode(['ok' => true] + $retention->run()));

        return self::SUCCESS;
    }
}
