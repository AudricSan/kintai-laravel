<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * Chiffres des widgets du tableau de bord gérant, pour les magasins que l'utilisateur voit : aperçu des statistiques,
 * masse salariale du mois, absentéisme. Les montants ne s'additionnent que dans une même devise : la masse salariale
 * est donc donnée par devise.
 */
class DashboardFigures
{
    /** Période des statistiques du tableau de bord, en jours. */
    public const PERIOD_DAYS = 30;

    /** Période lue pour la tendance de la masse salariale, en jours (six mois). */
    public const TREND_DAYS = 180;

    public function __construct(private readonly StoreStatsService $stats) {}

    /**
     * Une ligne par magasin (voir `StoreStatsService::multiStoreComparison`) et, pour un seul magasin, les heures par semaine.
     *
     * @param  list<int>  $storeIds
     * @return array{rows: list<array<string, mixed>>, hoursByWeek: array<string, float>}
     */
    public function summary(array $storeIds): array
    {
        $rows = $this->stats->multiStoreComparison($storeIds, self::PERIOD_DAYS);
        $hoursByWeek = count($rows) === 1 ? $this->stats->storeStats($rows[0]['store_id'], self::PERIOD_DAYS)['hoursByWeek'] : [];

        return ['rows' => $rows, 'hoursByWeek' => $hoursByWeek];
    }

    /**
     * Masse salariale du mois en cours et du mois précédent, et tendance des six derniers mois, par devise.
     *
     * @param  list<int>  $storeIds
     * @return list<array{currency: string, style: string, current: float, previous: float, delta: float|null, trend: array<string, float>}>
     */
    public function finance(array $storeIds): array
    {
        $now = CarbonImmutable::now((string) config('kintai.timezone', 'UTC'));
        $currentKey = $now->format('Y-m');
        $previousKey = $now->startOfMonth()->subMonth()->format('Y-m');
        $groups = [];

        foreach (Store::query()->whereIn('id', $storeIds)->orderBy('name')->orderBy('id')->get() as $store) {
            $currency = strtoupper($store->currency);
            $groups[$currency] ??= ['style' => $store->currency_symbol_style, 'months' => []];

            foreach ($this->stats->costByMonth($store->id, self::TREND_DAYS) as $month => $cost) {
                $groups[$currency]['months'][$month] = ($groups[$currency]['months'][$month] ?? 0.0) + $cost;
            }
        }

        $result = [];
        foreach ($groups as $currency => $group) {
            ksort($group['months']);
            $current = (float) ($group['months'][$currentKey] ?? 0.0);
            $previous = (float) ($group['months'][$previousKey] ?? 0.0);

            $result[] = [
                'currency' => $currency,
                'style' => $group['style'],
                'current' => round($current, 2),
                'previous' => round($previous, 2),
                'delta' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
                'trend' => array_slice($group['months'], -6, 6, true),
            ];
        }

        return $result;
    }

    /**
     * Taux d'absentéisme moyen des magasins et congés approuvés commencés sur la période. Retards et heures
     * supplémentaires ne sont pas calculés (voir le README).
     *
     * @param  list<int>  $storeIds
     * @return array{absenceRate: float, approved: int, approvedDays: float}
     */
    public function absence(array $storeIds): array
    {
        $rates = [];
        $approved = 0;
        $days = 0.0;

        foreach ($storeIds as $storeId) {
            $stats = $this->stats->storeStats($storeId, self::PERIOD_DAYS);
            $rates[] = (float) $stats['absRate'];
            $approved += (int) $stats['timeoffsByStatus']['approved'];
            $days += (float) $stats['approvedDays'];
        }

        return [
            'absenceRate' => $rates === [] ? 0.0 : round(array_sum($rates) / count($rates), 1),
            'approved' => $approved,
            'approvedDays' => $days,
        ];
    }
}
