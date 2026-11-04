<?php

namespace App\Exports;

use App\Exports\Datasets\AvailabilitiesDataset;
use App\Exports\Datasets\EmployeesDataset;
use App\Exports\Datasets\PayrollDataset;
use App\Exports\Datasets\ShiftsDataset;
use App\Exports\Datasets\ShiftTypesDataset;
use App\Exports\Datasets\StoresDataset;
use Illuminate\Contracts\Container\Container;

/**
 * Jeux de données exportables du Core. Les bundles déclarent les leurs dans `ExportDatasetRegistry`.
 */
final class CoreExportDatasets
{
    public static function registerIn(ExportDatasetRegistry $registry, Container $container): void
    {
        foreach ([
            ShiftsDataset::class,
            EmployeesDataset::class,
            AvailabilitiesDataset::class,
            ShiftTypesDataset::class,
            StoresDataset::class,
            PayrollDataset::class,
        ] as $dataset) {
            $registry->register($container->make($dataset));
        }
    }
}
