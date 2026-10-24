<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;

final class EmployeeStatsService
{
    /** Clés de traduction du Core (lang/*.json) associées à chaque mois. */
    private const MONTH_KEYS = [
        '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
        '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
        '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December',
    ];

    public function __construct(
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly UserShiftTypeRateRepositoryInterface $userRates,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly StoreRepositoryInterface $stores,
    ) {}

    public function calculate(int $userId, string $month = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $monthStart = $month . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $prevMonth  = date('Y-m', strtotime($monthStart . ' -1 month'));
        $nextMonth  = date('Y-m', strtotime($monthStart . ' +1 month'));

        $weekSet = [];
        $cur = new \DateTime($monthStart);
        $endDt = new \DateTime($monthEnd);
        while ($cur <= $endDt) {
            $weekSet[$cur->format('o-W')] = true;
            $cur->modify('+1 day');
        }
        $numWeeks = max(1, count($weekSet));

        $typesMap = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $typesMap[(int) $t['id']] = $t;
        }
        $personalRates = [];
        foreach ($this->userRates->findByUser($userId) as $r) {
            $personalRates[(int) $r['shift_type_id']] = (float) $r['hourly_rate'];
        }

        $memberships  = $this->storeUsers->findByUser($userId);
        $currency     = 'JPY';
        $currencyStyle = 'kanji';
        if (!empty($memberships)) {
            $store         = $this->stores->findById((int) $memberships[0]['store_id']);
            $currency      = strtoupper(trim($store['currency'] ?? 'JPY'));
            $currencyStyle = store_currency_style($store);
        }

        $monthMinutes = 0;
        $estimatedPay = 0.0;
        $hasRate      = false;
        $shiftDetails = [];
        $wageCalc     = new ShiftWageCalculator();

        foreach ($this->shifts->findByUser($userId) as $s) {
            if (!empty($s['deleted_at'])) {
                continue;
            }
            $d = $s['shift_date'] ?? '';
            if ($d < $monthStart || $d > $monthEnd) {
                continue;
            }

            $tid  = (int) ($s['shift_type_id'] ?? 0);
            $wage = $wageCalc->costOf($s, $typesMap, $personalRates);
            $netMin = $wage['net_minutes'];
            $rate   = $wage['rate'];
            $pay    = $wage['amount'];
            $monthMinutes += $netMin;
            if ($rate > 0) {
                $hasRate = true;
            }
            $estimatedPay += $pay;

            $dt       = new \DateTimeImmutable($d);
            $dateLabel = __(strtolower($dt->format('D')) . '_abbr')
                . ' ' . $dt->format('j')
                . ' ' . __(self::MONTH_KEYS[$dt->format('m')] ?? $dt->format('M'));
            $h = intdiv($netMin, 60);
            $m = $netMin % 60;
            $shiftDetails[] = [
                'date'          => $d,
                'date_label'    => $dateLabel,
                'start'         => substr($s['start_time'] ?? '—', 0, 5),
                'end'           => substr($s['end_time']   ?? '—', 0, 5),
                'type_name'     => $typesMap[$tid]['name'] ?? '—',
                'net_min'       => $netMin,
                'net_hours_fmt' => $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT),
                'pause_min'     => $wage['pause_minutes'],
                'rate'          => $rate,
                'rate_fmt'      => $rate > 0 ? format_currency($rate, $currency, $currencyStyle) . __('per_hour_unit') : '—',
                'pay_fmt'       => $rate > 0 ? format_currency($pay, $currency, $currencyStyle) : '—',
                'has_rate'      => $rate > 0,
            ];
        }

        usort($shiftDetails, fn($a, $b) => strcmp($a['date'] . $a['start'], $b['date'] . $b['start']));

        [$y, $mNum] = array_pad(explode('-', $month), 2, '');
        $monthLabel = month_label($y . '-' . $mNum);

        return [
            'hours_month'   => $monthMinutes / 60,
            'hours_week'    => ($monthMinutes / 60) / $numWeeks,
            'estimated_pay' => $estimatedPay,
            'has_rate'      => $hasRate,
            'month_label'   => $monthLabel,
            'month_key'     => $month,
            'prev_month'    => $prevMonth,
            'next_month'    => $nextMonth,
            'is_current'    => ($month === date('Y-m')),
            'currency'               => $currency,
            'currency_symbol_style'  => $currencyStyle,
            'shift_details'          => $shiftDetails,
        ];
    }
}
