<?php

namespace App\Exports;

use InvalidArgumentException;

/**
 * Point d'extension : le Core et les bundles y déclarent les jeux de données exportables (page « Exports »).
 */
class ExportDatasetRegistry
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
