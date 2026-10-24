<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\ExportDataset;
use kintai\Core\Container;
use kintai\Core\Export\AvailabilitiesExport;
use kintai\Core\Export\EmployeesExport;
use kintai\Core\Export\PayrollExport;
use kintai\Core\Export\ShiftsExport;
use kintai\Core\Export\ShiftTypesExport;
use kintai\Core\Export\StoresExport;

/**
 * Catalogue des jeux de données exportables : ceux du Core, puis ceux des bundles actifs (point d'extension
 * Bundle::exportDatasets(), contrat 1.30). Une clé déjà prise n'est pas reprise : un bundle ne peut ni remplacer ni
 * masquer un jeu de données du Core.
 */
final class ExportDatasets
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou le seul catalogue du Core si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /**
     * Tous les jeux de données, par clé, triés par ordre puis par clé.
     *
     * @return array<string, ExportDataset>
     */
    public function all(): array
    {
        $all = [];
        foreach ([...$this->core(), ...$this->bundles()] as $dataset) {
            $all[$dataset->key] ??= $dataset;
        }
        uasort($all, static fn(ExportDataset $a, ExportDataset $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $all;
    }

    public function find(string $key): ?ExportDataset
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<ExportDataset> */
    private function core(): array
    {
        $c = Container::getInstance();

        return [
            new ExportDataset('shifts', 'export_ds_shifts', 'shifts.view',
                $this->columns(['date', 'start_time', 'end_time', 'ends_next_day', 'break_minutes', 'net_minutes', 'shift_type', 'employee', 'store', 'notes']),
                $c->make(ShiftsExport::class), selfService: true, order: 10),
            new ExportDataset('employees', 'export_ds_employees', 'employees.view',
                $this->columns(['store', 'employee_id', 'last_name', 'first_name', 'display_name', 'email', 'phone', 'mobile_phone', 'postal_code', 'address', 'language', 'active']),
                $c->make(EmployeesExport::class), selfService: true, hasPeriod: false, order: 20),
            new ExportDataset('availabilities', 'export_ds_availabilities', 'shifts.view',
                $this->columns(['store', 'employee', 'day_of_week', 'start_time', 'end_time', 'available']),
                $c->make(AvailabilitiesExport::class), selfService: true, hasPeriod: false, order: 30),
            new ExportDataset('shift_types', 'export_ds_shift_types', 'shifts.view',
                $this->columns(['store', 'type_id', 'name', 'start_time', 'end_time', 'active']),
                $c->make(ShiftTypesExport::class), perPerson: false, hasPeriod: false, order: 40),
            new ExportDataset('stores', 'export_ds_stores', 'stores.view',
                $this->columns(['store_id', 'code', 'name', 'timezone', 'locale', 'currency', 'active']),
                $c->make(StoresExport::class), perPerson: false, hasPeriod: false, order: 50),
            new ExportDataset('payroll', 'export_ds_payroll', 'payroll.export',
                $this->columns(['date', 'store', 'employee', 'shift_type', 'net_minutes', 'hourly_rate', 'amount', 'currency']),
                $c->make(PayrollExport::class), order: 60),
        ];
    }

    /** @return list<ExportDataset> */
    private function bundles(): array
    {
        $datasets = [];
        foreach ($this->registry?->exportDatasetsByBundle() ?? [] as $declared) {
            array_push($datasets, ...$declared);
        }

        return $datasets;
    }

    /**
     * En-têtes du Core : la clé de traduction se déduit du nom de la colonne.
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    private function columns(array $names): array
    {
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = 'export_col_' . $name;
        }

        return $columns;
    }
}
