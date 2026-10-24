<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Services\ScheduleExportService;

/** Jeu de données « Horaires » : un shift par ligne, dans l'ordre chronologique (shifts supprimés exclus). */
final class ShiftsExport implements ExportRowsProvider
{
    public function __construct(
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly ScheduleExportService $schedule,
        private readonly ExportDirectory $directory,
    ) {}

    public function rows(ExportQuery $query): iterable
    {
        $types = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $types[(int) $t['id']] = $t;
        }

        $rows = [];
        foreach ($query->storeIds as $storeId) {
            $store = $this->directory->store($storeId) ?? ['id' => $storeId];
            $mine  = array_values(array_filter(
                $this->shifts->findByStoreBetween($storeId, $query->from, $query->to),
                fn(array $s): bool => isset($s['user_id']) && $query->includesUser((int) $s['user_id'])
            ));

            // La mise à plat est celle de l'export personnel (durée nette, fin le lendemain…), une ligne par shift.
            foreach ($this->schedule->select($mine, $query->from, $query->to) as $shift) {
                $flat = $this->schedule->rows([$shift], $store, $types)[0];
                $rows[] = ['employee' => $this->directory->userName((int) ($shift['user_id'] ?? 0))] + $flat;
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$a['date'], $a['start_time'], $a['store'], $a['employee']] <=> [$b['date'], $b['start_time'], $b['store'], $b['employee']]);

        return $rows;
    }
}
