<?php

namespace App\Console\Commands;

use App\Actions\License\ValidateLicense;
use App\License\LicenseState;
use Illuminate\Console\Command;

/**
 * Valide la licence en ligne et met à jour son état local (planifiée chaque jour, seulement si une clé est réglée). Ne plante
 * jamais sur une panne de réseau : le jeton déjà reçu continue de couvrir l'instance jusqu'à son échéance.
 */
class ValidateLicenseCommand extends Command
{
    protected $signature = 'kintai:license:validate';

    protected $description = 'Validate the license online and refresh its local state';

    public function handle(ValidateLicense $validate, LicenseState $state): int
    {
        if (! $state->hasKey()) {
            $this->components->info('No license key is set.');

            return self::SUCCESS;
        }

        $result = $validate->handle();
        if ($state->status()->productKeyRejected()) {
            $this->components->warn('The product key is refused by the license server.');
        }

        $this->components->info('License state: '.$state->state().($result?->isAnswer() === false ? ' (server not reachable).' : '.'));

        return self::SUCCESS;
    }
}
