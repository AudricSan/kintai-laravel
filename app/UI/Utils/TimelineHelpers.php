<?php

declare(strict_types=1);

namespace kintai\UI\Utils;

final class TimelineHelpers
{
    public static function atMin(string $t): int
    {
        $parts = explode(':', substr($t, 0, 5));
        return (int) ($parts[0] ?? 0) * 60 + (int) ($parts[1] ?? 0);
    }

    public static function atFmt(int $min): string
    {
        $m = $min % 1440;
        return str_pad((string) intdiv($m, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function atLanes(array $rawShifts, int $tStart): array
    {
        $lanes = []; $laneEnds = [];
        foreach ($rawShifts as $sh) {
            $sm = self::atMin($sh['start_time'] ?? '00:00');
            $em = self::atMin($sh['end_time']   ?? '00:00');
            if (!empty($sh['cross_midnight']) || $em <= $sm) $em += 1440;
            if ($sm < $tStart) { $sm += 1440; $em += 1440; }
            $sh['_sm'] = $sm; $sh['_em'] = $em;
            $placed = false;
            foreach ($laneEnds as $li => $lEnd) {
                if ($lEnd <= $sm) { $lanes[$li][] = $sh; $laneEnds[$li] = $em; $placed = true; break; }
            }
            if (!$placed) { $lanes[] = [$sh]; $laneEnds[] = $em; }
        }
        return $lanes;
    }

    /**
     * @param array $typeStoreIds id de type de shift => liste des store_id où il est activé
     *                            (un type peut désormais couvrir plusieurs stores).
     */
    public static function atPayBreakdown(
        string $startTime, string $endTime, int $pauseMin, bool $crossMidnight,
        int $uid, int $storeId, array $typesMap, array $typeStoreIds, array $ratesMap, string $currency,
        string $currencySymbolStyle = 'kanji'
    ): array {
        $sm = self::atMin($startTime); $em = self::atMin($endTime);
        if ($crossMidnight || $em <= $sm) $em += 1440;
        $grossMin = $em - $sm;
        if ($pauseMin > 0 && $grossMin > $pauseMin) {
            $mid = $sm + intdiv($grossMin, 2);
            $ps  = $mid - intdiv($pauseMin, 2);
            $segments = [[$sm, $ps], [$ps + $pauseMin, $em]];
        } else { $segments = [[$sm, $em]]; }
        $netMin = array_sum(array_map(fn($s) => $s[1] - $s[0], $segments));
        $storeTypes = array_filter($typesMap, fn($t) => in_array($storeId, $typeStoreIds[(int) $t['id']] ?? [], true));
        $minByType  = [];
        foreach ($storeTypes as $tid => $type) {
            $ts = self::atMin($type['start_time']); $te = self::atMin($type['end_time']);
            if ($te <= $ts) $te += 1440;
            $overlap = 0;
            foreach ($segments as [$ss, $se]) {
                foreach ([-1440, 0, 1440] as $offset) {
                    $ov = min($se, $te + $offset) - max($ss, $ts + $offset);
                    if ($ov > 0) $overlap += $ov;
                }
            }
            if ($overlap > 0) $minByType[$tid] = $overlap;
        }
        if (empty($minByType)) $netMin = max(0, $grossMin - $pauseMin);
        $totalPay = 0.0; $hasRate = false; $items = [];
        foreach ($minByType as $tid => $minutes) {
            $type = $typesMap[$tid] ?? [];
            $rate = $ratesMap[$uid][$tid] ?? (float) ($type['hourly_rate'] ?? 0);
            $pay  = ($minutes / 60) * $rate;
            $totalPay += $pay;
            if ($rate > 0) $hasRate = true;
            $items[] = [
                'type_name' => $type['name'] ?? '?', 'minutes' => $minutes,
                'rate' => $rate, 'rate_fmt' => $rate > 0 ? format_currency($rate, $currency, $currencySymbolStyle) . __('per_hour_unit') : '',
                'pay_fmt' => $rate > 0 ? format_currency($pay, $currency, $currencySymbolStyle) : '', 'has_rate' => $rate > 0,
            ];
        }
        return ['total' => $totalPay, 'has_rate' => $hasRate, 'net_minutes' => $netMin, 'items' => $items];
    }
}
