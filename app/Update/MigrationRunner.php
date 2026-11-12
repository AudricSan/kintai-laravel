<?php

namespace App\Update;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Étapes de `UpdateApplier::finish()` qui dépendent du framework : migrations et vidage des caches. Séparées pour pouvoir
 * être remplacées dans les tests (qui ne doivent ni migrer la base ni vider les vues du vrai dépôt).
 */
class MigrationRunner
{
    /**
     * @return string Sortie des migrations.
     *
     * @throws UpdateException
     */
    public function run(): string
    {
        $status = Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        if ($status !== 0) {
            throw new UpdateException(__('The migrations failed.').($output === '' ? '' : ' '.$output));
        }

        return $output;
    }

    /** Invalide opcache et les vues compilées, que le nouveau code rend périmés. */
    public function clearCaches(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        try {
            Artisan::call('view:clear');
        } catch (Throwable) {
            // Les vues compilées se régénèrent d'elles-mêmes : un échec ici n'est pas bloquant.
        }
    }
}
