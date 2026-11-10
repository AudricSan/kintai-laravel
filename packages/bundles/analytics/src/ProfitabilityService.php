<?php

namespace Kintai\Bundles\Analytics;

use Carbon\CarbonImmutable;
use Kintai\Contracts\Sales\DailySalesProvider;

/**
 * Rentabilité d'un magasin : ventes, clients et coût de main-d'œuvre saisi de chaque jour (fournisseur de ventes du contrat,
 * donc le bundle daily-report) face au coût du personnel calculé depuis les shifts du jour. Tous les statuts de saisie sont
 * lus ; le statut figure sur chaque ligne. Sans fournisseur de ventes actif, `available` vaut faux et il n'y a aucune ligne.
 */
class ProfitabilityService
{
    public function __construct(
        private readonly DailySalesProvider $sales,
        private readonly StoreShifts $shifts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function storeProfitability(int $storeId, string $from, string $to): array
    {
        $days = $this->sales->available()
            ? $this->sales->dailySales($storeId, CarbonImmutable::parse($from), CarbonImmutable::parse($to))
            : [];

        $payrollByDate = [];
        $hoursByDate = [];
        if ($days !== []) {
            foreach ($this->shifts->between($storeId, $from, $to) as $shift) {
                $payrollByDate[$shift->date] = ($payrollByDate[$shift->date] ?? 0.0) + $shift->wage->total;
                $hoursByDate[$shift->date] = ($hoursByDate[$shift->date] ?? 0.0) + $shift->wage->netMinutes / 60;
            }
        }

        $rows = [];
        $totalSales = 0.0;
        $totalLaborMnl = 0.0;
        $totalLaborCalc = 0.0;
        $totalCustomers = 0;
        $salesByMonth = [];
        $laborMnlByMonth = [];
        $laborCalcByMonth = [];
        $lcPctByMonth = [];
        $customersByMonth = [];

        foreach ($days as $day) {
            $calc = $payrollByDate[$day->date] ?? 0.0;

            $rows[] = [
                'date' => $day->date,
                'status' => $day->status,
                'sales' => $day->sales,
                'labor_mnl' => $day->laborCost,
                'labor_calc' => $calc,
                'hours' => round($hoursByDate[$day->date] ?? 0.0, 1),
                'customers' => $day->customers,
                'rev_per_cust' => $day->customers > 0 ? round($day->sales / $day->customers, 2) : null,
                'lc_pct' => $day->sales > 0 ? round($day->laborCost / $day->sales * 100, 1) : null,
                'lc_pct_calc' => $day->sales > 0 ? round($calc / $day->sales * 100, 1) : null,
                'variance' => round($day->laborCost - $calc, 2),
            ];

            $totalSales += $day->sales;
            $totalLaborMnl += $day->laborCost;
            $totalLaborCalc += $calc;
            $totalCustomers += $day->customers;

            $month = substr($day->date, 0, 7);
            $salesByMonth[$month] = ($salesByMonth[$month] ?? 0.0) + $day->sales;
            $laborMnlByMonth[$month] = ($laborMnlByMonth[$month] ?? 0.0) + $day->laborCost;
            $laborCalcByMonth[$month] = ($laborCalcByMonth[$month] ?? 0.0) + $calc;
            $customersByMonth[$month] = ($customersByMonth[$month] ?? 0) + $day->customers;
            if ($salesByMonth[$month] > 0) {
                $lcPctByMonth[$month] = round($laborMnlByMonth[$month] / $salesByMonth[$month] * 100, 1);
            }
        }
        ksort($salesByMonth);
        ksort($laborMnlByMonth);
        ksort($lcPctByMonth);
        ksort($laborCalcByMonth);
        ksort($customersByMonth);

        $salesByDay = [];
        $lcPctByDay = [];
        foreach ($rows as $row) {
            $salesByDay[$row['date']] = $row['sales'];
            $lcPctByDay[$row['date']] = $row['lc_pct'];
        }

        return [
            'available' => $this->sales->available(),
            'rows' => $rows,
            'totalSales' => round($totalSales, 2),
            'totalLaborMnl' => round($totalLaborMnl, 2),
            'totalLaborCalc' => round($totalLaborCalc, 2),
            'totalCustomers' => $totalCustomers,
            'avgLaborPct' => $totalSales > 0 ? round($totalLaborMnl / $totalSales * 100, 1) : null,
            'revPerCust' => $totalCustomers > 0 ? round($totalSales / $totalCustomers, 2) : null,
            'variance' => round($totalLaborMnl - $totalLaborCalc, 2),
            'reportCount' => count(array_filter($rows, fn (array $row): bool => $row['sales'] > 0)),
            'salesByMonth' => $salesByMonth,
            'laborMnlByMonth' => $laborMnlByMonth,
            'laborCalcByMonth' => $laborCalcByMonth,
            'lcPctByMonth' => $lcPctByMonth,
            'customersByMonth' => $customersByMonth,
            'salesByDay' => $salesByDay,
            'lcPctByDay' => $lcPctByDay,
        ];
    }
}
