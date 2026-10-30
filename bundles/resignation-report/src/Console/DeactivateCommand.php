<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ResignationReport\Console;

use Illuminate\Console\Command;
use kintai\Bundles\Installed\ResignationReport\Services\ResignationDeactivation;
use kintai\Contract\Services\AuditLogger;

/** Désactive le compte des démissionnaires dont la date de démission est atteinte. Planifiée chaque jour par ResignationReportBundle. */
final class DeactivateCommand extends Command
{
    protected $signature = 'kintai:resignation-deactivate';

    protected $description = 'Désactivation des comptes des démissionnaires';

    public function handle(ResignationDeactivation $deactivation, AuditLogger $audit): int
    {
        $applied = $deactivation->applyDue();

        foreach ($applied as $reportId) {
            $audit->log(request(), 'resignation_report.deactivated', 'resignation_report', $reportId, ['reason' => 'resignation_date_reached']);
        }
        $this->line(json_encode(['ok' => true, 'deactivated' => count($applied), 'reports' => $applied]));

        return self::SUCCESS;
    }
}
