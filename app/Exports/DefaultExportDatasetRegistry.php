<?php

namespace App\Exports;

use InvalidArgumentException;
use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportDatasetRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultExportDatasetRegistry implements ExportDatasetRegistry
{
    /** @var array<string, ExportDataset> */
    private array $datasets = [];

    public function register(ExportDataset $dataset): void
    {
        if (isset($this->datasets[$dataset->key()])) {
            throw new InvalidArgumentException("Export dataset [{$dataset->key()}] is already registered.");
        }

        $this->datasets[$dataset->key()] = $dataset;
    }

    public function find(string $key): ?ExportDataset
    {
        return $this->datasets[$key] ?? null;
    }

    /**
     * Dans l'ordre d'enregistrement.
     *
     * @return array<string, ExportDataset>
     */
    public function all(): array
    {
        return $this->datasets;
    }
}
