<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;

/** Jeu de données « Magasins » : une ligne par magasin autorisé. Pas de réglages internes (jetons, intégrations). */
final class StoresExport implements ExportRowsProvider
{
    public function __construct(private readonly ExportDirectory $directory) {}

    public function rows(ExportQuery $query): iterable
    {
        $rows = [];
        foreach ($query->storeIds as $storeId) {
            $store = $this->directory->store($storeId);
            if ($store === null) {
                continue;
            }
            $rows[] = [
                'store_id' => $storeId,
                'code'     => (string) ($store['code'] ?? ''),
                'name'     => (string) ($store['name'] ?? ''),
                'timezone' => (string) ($store['timezone'] ?? ''),
                'locale'   => (string) ($store['locale'] ?? ''),
                'currency' => (string) ($store['currency'] ?? ''),
                'active'   => !isset($store['is_active']) || !empty($store['is_active']),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
    }
}
