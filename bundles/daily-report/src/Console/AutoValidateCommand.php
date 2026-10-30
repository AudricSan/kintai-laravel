<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\DailyReport\Console;

use Illuminate\Console\Command;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportAutoValidateService;
use kintai\Contract\Services\AuditLogger;

/**
 * Valide les rapports journaliers restés non validés après l'heure limite de leur magasin. Planifiée chaque heure par
 * DailyReportBundle ; lançable à la main : php artisan kintai:auto-validate --date=2026-10-13 --all
 */
final class AutoValidateCommand extends Command
{
    protected $signature = 'kintai:auto-validate
        {--date= : Jour à valider (Y-m-d, défaut : hier)}
        {--all : Tous les magasins, sans filtre horaire}';

    protected $description = 'Validation automatique des rapports journaliers';

    public function handle(DailyReportAutoValidateService $autoValidate, AuditLogger $audit): int
    {
        set_time_limit(0);

        $rawDate    = (string) $this->option('date');
        $targetDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) === 1 ? $rawDate : date('Y-m-d', strtotime('yesterday'));
        $result     = $autoValidate->run($targetDate, $this->option('all') ? null : date('H'));

        $audit->log(request(), 'cron.auto_validated', 'system', null, [
            'date'      => $targetDate,
            'validated' => $result['validated'],
            'skipped'   => $result['skipped'],
            'errors'    => $result['errors'] ?? 0,
        ]);
        $this->line(json_encode(['ok' => true, 'date' => $targetDate, 'validated' => $result['validated'], 'skipped' => $result['skipped'], 'errors' => $result['errors'] ?? 0]));

        return self::SUCCESS;
    }
}
