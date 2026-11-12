<?php

namespace Kintai\Bundles\ResignationReport\Console;

use Illuminate\Console\Command;
use Kintai\Bundles\ResignationReport\ResignationDeactivation;

/**
 * Désactive les comptes dont le rapport de départ est échu (date atteinte ou absente) et pas encore appliqué. Planifiée
 * chaque jour par le bundle ; idempotente : un rapport n'est appliqué qu'une fois. Un garde-fou du Core (dernier Owner)
 * qui refuse n'interrompt pas la commande : le rapport est ignoré et le refus journalisé.
 */
class DeactivateCommand extends Command
{
    protected $signature = 'kintai:resignation-deactivate';

    protected $description = 'Deactivate the accounts of employees whose resignation date has been reached';

    public function handle(ResignationDeactivation $deactivation): int
    {
        $this->line((string) json_encode(['ok' => true] + $deactivation->applyDue()));

        return self::SUCCESS;
    }
}
