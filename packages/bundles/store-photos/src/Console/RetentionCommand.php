<?php

namespace Kintai\Bundles\StorePhotos\Console;

use Illuminate\Console\Command;
use Kintai\Bundles\StorePhotos\PhotoRetention;

/**
 * Applique la rétention des photos de magasin (voir `PhotoRetention`). Planifiée chaque nuit par le bundle.
 */
class RetentionCommand extends Command
{
    protected $signature = 'kintai:photo-retention';

    protected $description = 'Apply the retention period of the store photos';

    public function handle(PhotoRetention $retention): int
    {
        $this->line((string) json_encode(['ok' => true] + $retention->run()));

        return self::SUCCESS;
    }
}
