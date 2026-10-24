<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;

/**
 * Jeu de données « Types de shift » : un type par ligne et par magasin où il est activé. Les taux horaires n'y figurent pas
 * (ce sont des données de paie, exportées par le jeu « Paie » sous sa propre permission).
 */
final class ShiftTypesExport implements ExportRowsProvider
{
    public function __construct(
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly ExportDirectory $directory,
    ) {}

    public function rows(ExportQuery $query): iterable
    {
        $rows = [];
        foreach ($query->storeIds as $storeId) {
            foreach ($this->shiftTypes->findByStore($storeId) as $type) {
                $rows[] = [
                    'store'      => $this->directory->storeName($storeId),
                    'type_id'    => (int) ($type['id'] ?? 0),
                    'name'       => (string) ($type['name'] ?? ''),
                    'start_time' => substr((string) ($type['start_time'] ?? ''), 0, 5),
                    'end_time'   => substr((string) ($type['end_time'] ?? ''), 0, 5),
                    'active'     => !isset($type['is_active']) || !empty($type['is_active']),
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$a['store'], $a['start_time'], $a['name']] <=> [$b['store'], $b['start_time'], $b['name']]);

        return $rows;
    }
}
