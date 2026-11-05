<?php

namespace App\Console\Commands;

use App\Docs\DocsSync;
use Illuminate\Console\Command;

/**
 * Synchronise la documentation intégrée depuis le wiki GitHub (`kintai.docs.repository`). Planifiée chaque jour ; le bouton
 * « Synchroniser » de la page Documentation (Owner) fait la même chose.
 */
class SyncDocsCommand extends Command
{
    protected $signature = 'kintai:docs:sync';

    protected $description = 'Download the documentation pages from the GitHub wiki';

    public function handle(DocsSync $sync): int
    {
        $result = $sync->sync();

        foreach ($result['errors'] as $error) {
            $this->components->error($error);
        }

        $this->components->{$result['ok'] ? 'info' : 'warn'}(sprintf(
            'Documentation: %d added, %d updated, %d unchanged, %d skipped, %d removed.',
            $result['added'], $result['updated'], $result['unchanged'], $result['skipped'], $result['removed'],
        ));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
