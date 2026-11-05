<?php

namespace Kintai\Contracts\Exports;

/**
 * Point d'extension : le Core et les bundles y déclarent les jeux de données exportables (page « Exports »).
 */
interface ExportDatasetRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(ExportDataset $dataset): void;

    public function find(string $key): ?ExportDataset;

    /**
     * Dans l'ordre d'enregistrement.
     *
     * @return array<string, ExportDataset>
     */
    public function all(): array;
}
