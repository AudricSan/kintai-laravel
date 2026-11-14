<?php

namespace Kintai\Bundles\DailyReport;

use Carbon\CarbonInterface;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use Kintai\Contracts\Sales\DailySales;
use Kintai\Contracts\Sales\DailySalesProvider;

/**
 * Les ventes journalières tirées des rapports journaliers, vues par le contrat (rentabilité du bundle analytics…). Le
 * bundle remplace la liaison par défaut du Core (vide) tant qu'il est activé. Le filtre de statut s'applique avant la
 * conversion en valeurs du jour, pour un magasin qui saisit en cumulé (`cumulative_input`).
 */
class DailyReportSales implements DailySalesProvider
{
    private const CUMULATIVE_FIELDS = ['sales_total', 'customer_count', 'labor_cost', 'waste_total'];

    public function dailySales(int $storeId, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null): array
    {
        $reports = DailyReport::query()
            ->where('store_id', $storeId)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->orderBy('report_date')
            ->get();

        $cumulativeInput = DailyReportSetting::query()->firstOrNew(['store_id' => $storeId], DailyReportSetting::DEFAULTS)->cumulative_mode === 'cumulative_input';
        $previous = array_fill_keys(self::CUMULATIVE_FIELDS, 0.0);
        $days = [];

        foreach ($reports as $report) {
            $values = [];
            foreach (self::CUMULATIVE_FIELDS as $field) {
                $raw = (float) $report->{$field};
                // Saisie cumulée : la valeur du jour est l'écart avec la saisie précédente (jamais négatif).
                $values[$field] = $cumulativeInput ? max(0.0, $raw - $previous[$field]) : $raw;
                $previous[$field] = $raw;
            }

            $days[] = new DailySales(
                $report->report_date->toDateString(),
                $report->status,
                $values['sales_total'],
                $values['labor_cost'],
                (int) round($values['customer_count']),
                $values['waste_total'],
            );
        }

        return $days;
    }

    public function available(): bool
    {
        return true;
    }
}
