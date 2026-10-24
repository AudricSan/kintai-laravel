<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Services\ShiftWageCalculator;

/**
 * Jeu de données « Paie (estimation) » : le coût de chaque shift, calculé comme partout ailleurs par
 * ShiftWageCalculator::costOf() (taux du type, taux personnel prioritaire, surcharges manuelles du shift).
 * Donnée sensible : exigée sous `payroll.export`, jamais en libre-service.
 */
final class PayrollExport implements ExportRowsProvider
{
    public function __construct(
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly UserShiftTypeRateRepositoryInterface $rates,
        private readonly ShiftWageCalculator $wages,
        private readonly ExportDirectory $directory,
    ) {}

    public function rows(ExportQuery $query): iterable
    {
        $types = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $types[(int) $t['id']] = $t;
        }

        $personal = [];
        $rows     = [];
        foreach ($query->storeIds as $storeId) {
            $currency = (string) ($this->directory->store($storeId)['currency'] ?? '');

            foreach ($this->shifts->findByStoreBetween($storeId, $query->from, $query->to) as $shift) {
                $userId = (int) ($shift['user_id'] ?? 0);
                if (!empty($shift['deleted_at']) || $userId === 0 || !$query->includesUser($userId)) {
                    continue;
                }
                if (!isset($personal[$userId])) {
                    $personal[$userId] = [];
                    foreach ($this->rates->findByUser($userId) as $r) {
                        $personal[$userId][(int) $r['shift_type_id']] = (float) $r['hourly_rate'];
                    }
                }

                $cost = $this->wages->costOf($shift, $types, $personal[$userId]);
                $rows[] = [
                    'store'       => $this->directory->storeName($storeId),
                    'employee'    => $this->directory->userName($userId),
                    'date'        => (string) ($shift['shift_date'] ?? ''),
                    'shift_type'  => (string) ($types[(int) ($shift['shift_type_id'] ?? 0)]['name'] ?? ''),
                    'net_minutes' => $cost['net_minutes'],
                    'hourly_rate' => $cost['rate'],
                    'amount'      => $cost['amount'],
                    'currency'    => $currency,
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$a['date'], $a['store'], $a['employee']] <=> [$b['date'], $b['store'], $b['employee']]);

        return $rows;
    }
}
