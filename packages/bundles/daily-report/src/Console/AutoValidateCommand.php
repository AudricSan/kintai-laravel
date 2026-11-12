<?php

namespace Kintai\Bundles\DailyReport\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kintai\Bundles\DailyReport\AutoValidation;

/**
 * Valide les rapports de la veille restés non validés après l'heure choisie par leur magasin. Planifiée chaque heure par
 * le bundle ; à la main : `php artisan kintai:auto-validate --date=2026-11-05 --all`.
 */
class AutoValidateCommand extends Command
{
    protected $signature = 'kintai:auto-validate
        {--date= : Day to validate (Y-m-d, default: yesterday)}
        {--all : All stores, without the hour filter}';

    protected $description = 'Automatically validate the daily reports left unvalidated';

    public function handle(AutoValidation $autoValidation): int
    {
        $timezone = (string) config('kintai.timezone', 'UTC');
        $raw = (string) $this->option('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : Carbon::now($timezone)->subDay()->toDateString();

        $result = $autoValidation->run($date, $this->option('all') ? null : Carbon::now($timezone)->format('H'));
        $this->line((string) json_encode(['ok' => true, 'date' => $date] + $result));

        return self::SUCCESS;
    }
}
