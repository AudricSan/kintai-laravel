<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Store;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportScope;

/**
 * Jeu de données « Store statistics » des exports : pour chaque magasin du périmètre, les heures nettes et le coût estimé de
 * chaque employé sur la période (`StoreStatsService::storeStatsExportData`). Donnée de paie : exigée sous `payroll.export`,
 * jamais en libre-service. Les magasins et les employés viennent du périmètre que le Core autorise (`ExportScope`).
 */
class StoreStatsExport implements ExportDataset
{
    public const KEY = 'store-stats';

    public function __construct(private readonly StoreStatsService $stats) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Store statistics';
    }

    public function permission(): string
    {
        return AnalyticsAccess::EXPORT;
    }

    public function selfService(): bool
    {
        return false;
    }

    public function perEmployee(): bool
    {
        return true;
    }

    public function hasPeriod(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('store', 'Store'),
            new ExportColumn('employee', 'Employee'),
            new ExportColumn('net_hours', 'Net hours', ExportColumn::DECIMAL),
            new ExportColumn('amount', 'Estimated cost', ExportColumn::DECIMAL),
            new ExportColumn('currency', 'Currency'),
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'pdf'];
    }

    public function count(ExportScope $scope): int
    {
        return iterator_count($this->rows($scope));
    }

    public function rows(ExportScope $scope): iterable
    {
        $days = (int) $scope->from->diffInDays($scope->to);
        $stores = Store::query()->whereIn('id', $scope->storeIds)->orderBy('name')->orderBy('id')->get();
        $members = $scope->userIds === null ? null : array_flip($scope->userIds);
        $excluded = array_flip($scope->excludedUserIds);

        foreach ($stores as $store) {
            // La période du service finit à `to` et compte `$days` jours en arrière : elle commence à `from`.
            $data = $this->stats->storeStatsExportData($store->id, $days, $scope->to);

            foreach ($data['hoursByUser'] as $userId => $hours) {
                $userId = (int) $userId;
                if (($members !== null && ! isset($members[$userId])) || isset($excluded[$userId])) {
                    continue;
                }

                $user = $data['usersMap'][$userId] ?? null;
                yield [
                    'store' => $store->name,
                    'employee' => $user === null ? '#'.$userId : (string) ($user['name'] ?? '#'.$userId),
                    'net_hours' => round((float) $hours, 2),
                    'amount' => round((float) ($data['costByUser'][$userId] ?? 0), 2),
                    'currency' => $store->currency,
                ];
            }
        }
    }
}
