<?php

namespace Kintai\Bundles\PlanningCheck\Console;

use Illuminate\Console\Command;
use Kintai\Bundles\PlanningCheck\PlanningCheckActions;

/**
 * À l'heure limite d'un magasin, une validation du planning de demain encore non soumise est escaladée (valideurs et Owners
 * prévenus, aucun PDF envoyé). Planifiée toutes les 15 minutes par le bundle ; idempotente : une validation n'est escaladée
 * qu'une fois.
 */
class EscalateCommand extends Command
{
    protected $signature = 'kintai:planning-check-escalate';

    protected $description = 'Escalate the tomorrow schedules not submitted by the deadline';

    public function handle(PlanningCheckActions $actions): int
    {
        $this->line((string) json_encode(['ok' => true, 'escalated' => count($actions->escalate())]));

        return self::SUCCESS;
    }
}
