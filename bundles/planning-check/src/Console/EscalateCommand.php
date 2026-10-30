<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\PlanningCheck\Console;

use Illuminate\Console\Command;
use kintai\Bundles\Installed\PlanningCheck\Services\PlanningCheckService;
use kintai\Contract\Services\AuditLogger;

/**
 * À l'heure limite d'un magasin, une validation du planning de demain encore non soumise est escaladée (valideurs et
 * Owners prévenus, aucun PDF envoyé). Planifiée toutes les 15 minutes par PlanningCheckBundle ; sans planificateur, la
 * première page chargée après l'heure limite fait le même travail (voir PlanningCheckBundle::pageOverlays()).
 * Idempotente : une validation n'est escaladée qu'une fois.
 */
final class EscalateCommand extends Command
{
    protected $signature = 'kintai:planning-check-escalate';

    protected $description = 'Escalade des plannings non validés à l\'heure limite';

    public function handle(PlanningCheckService $service, AuditLogger $audit): int
    {
        $escalated = $service->escalate(new \DateTimeImmutable('now'));

        if ($escalated !== []) {
            $audit->log(request(), 'cron.planning_check_escalated', 'system', null, ['checks' => $escalated]);
        }
        $this->line(json_encode(['ok' => true, 'escalated' => count($escalated)]));

        return self::SUCCESS;
    }
}
