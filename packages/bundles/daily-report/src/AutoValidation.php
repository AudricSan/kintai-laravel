<?php

namespace Kintai\Bundles\DailyReport;

use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Throwable;

/**
 * Valide les rapports d'un jour restés non validés (brouillons compris) dans les magasins qui ont réglé une heure de
 * validation automatique. Lancée chaque heure : seuls les magasins dont l'heure réglée est l'heure courante sont traités,
 * sauf sans filtre horaire (`$hour` nul).
 */
class AutoValidation
{
    public function __construct(private readonly DailyReportAccess $access, private readonly DailyReportActions $actions) {}

    /**
     * @param  string|null  $hour  Heure courante « HH » ; nulle : tous les magasins.
     * @return array{validated: int, skipped: int, errors: int}
     */
    public function run(string $date, ?string $hour = null): array
    {
        $result = ['validated' => 0, 'skipped' => 0, 'errors' => 0];

        $settings = DailyReportSetting::query()->whereNotNull('auto_validate_time')->where('auto_validate_time', '!=', '')->get();
        $stores = $this->access->activeStores($settings->pluck('store_id')->map(fn ($id): int => (int) $id)->all());

        foreach ($settings as $setting) {
            if (! isset($stores[$setting->store_id]) || ($hour !== null && substr((string) $setting->auto_validate_time, 0, 2) !== $hour)) {
                $result['skipped']++;

                continue;
            }

            $report = DailyReport::query()->where('store_id', $setting->store_id)->whereDate('report_date', $date)->first();
            if ($report === null || $report->status === DailyReport::VALIDATED) {
                $result['skipped']++;

                continue;
            }

            try {
                $this->actions->autoValidate($report);
                $result['validated']++;
            } catch (Throwable $e) {
                report($e);
                $result['errors']++;
            }
        }

        return $result;
    }
}
