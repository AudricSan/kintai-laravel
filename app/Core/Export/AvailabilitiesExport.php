<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;

/** Jeu de données « Disponibilités » : les créneaux hebdomadaires déclarés par chaque employé, un par ligne. */
final class AvailabilitiesExport implements ExportRowsProvider
{
    public function __construct(
        private readonly AvailabilityRepositoryInterface $availabilities,
        private readonly ExportDirectory $directory,
    ) {}

    public function rows(ExportQuery $query): iterable
    {
        $rows = [];
        foreach ($query->storeIds as $storeId) {
            foreach ($this->availabilities->findByStore($storeId) as $a) {
                $userId = (int) ($a['user_id'] ?? 0);
                if (!$query->includesUser($userId)) {
                    continue;
                }
                $rows[] = [
                    'store'       => $this->directory->storeName($storeId),
                    'employee'    => $this->directory->userName($userId),
                    'day_of_week' => (int) ($a['day_of_week'] ?? 0),
                    'start_time'  => substr((string) ($a['start_time'] ?? ''), 0, 5),
                    'end_time'    => substr((string) ($a['end_time'] ?? ''), 0, 5),
                    'available'   => !empty($a['is_available']),
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$a['store'], $a['employee'], $a['day_of_week'], $a['start_time']] <=> [$b['store'], $b['employee'], $b['day_of_week'], $b['start_time']]);

        return $rows;
    }
}
