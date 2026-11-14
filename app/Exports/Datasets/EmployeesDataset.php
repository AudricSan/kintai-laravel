<?php

namespace App\Exports\Datasets;

use App\Exports\AbstractExportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportScope;

/**
 * « Employees » : une ligne par appartenance (employé x magasin) avec l'identité et les coordonnées. Jamais de mot de
 * passe, de jeton ni de donnée RH sensible (naissance, classement fiscal, garant) : seulement ce qu'une fiche
 * employé montre à un gérant pour le contact.
 */
class EmployeesDataset extends AbstractExportDataset
{
    public function key(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Employees';
    }

    public function permission(): string
    {
        return 'employees.view';
    }

    public function selfService(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('store', 'Store'),
            new ExportColumn('employee_id', 'Employee ID', ExportColumn::INTEGER),
            new ExportColumn('last_name', 'Last name'),
            new ExportColumn('first_name', 'First name'),
            new ExportColumn('name', 'Display name'),
            new ExportColumn('email', 'E-mail'),
            new ExportColumn('phone', 'Phone'),
            new ExportColumn('mobile_phone', 'Mobile phone'),
            new ExportColumn('postal_code', 'Postal code'),
            new ExportColumn('address', 'Address'),
            new ExportColumn('locale', 'Language'),
            new ExportColumn('active', 'Active', ExportColumn::BOOLEAN),
        ];
    }

    protected function query(ExportScope $scope): Builder
    {
        // Requête tabulaire à jointures et alias, lue en flux (lazy) : le query builder évite l'hydratation de modèles
        // et garde les valeurs brutes (heures, booléens) que `row()` met en forme.
        $query = DB::table('store_user')
            ->join('users', 'users.id', '=', 'store_user.user_id')
            ->join('stores', 'stores.id', '=', 'store_user.store_id')
            ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->whereIn('store_user.store_id', $scope->storeIds)
            ->select([
                'stores.name as store_name',
                'users.id as user_id',
                'users.last_name', 'users.first_name', 'users.name', 'users.email', 'users.locale',
                'users.is_active as user_active',
                'store_user.is_active as membership_active',
                'employee_profiles.phone', 'employee_profiles.mobile_phone',
                'employee_profiles.postal_code', 'employee_profiles.address',
            ])
            ->orderBy('stores.name')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->orderBy('store_user.id');

        $scope->applyUsers($query, 'users.id');

        return $query;
    }

    protected function row(mixed $record): array
    {
        return [
            'store' => $record->store_name,
            'employee_id' => (int) $record->user_id,
            'last_name' => $record->last_name,
            'first_name' => $record->first_name,
            'name' => $record->name,
            'email' => $record->email ?? '',
            'phone' => $record->phone ?? '',
            'mobile_phone' => $record->mobile_phone ?? '',
            'postal_code' => $record->postal_code ?? '',
            'address' => $record->address ?? '',
            'locale' => $record->locale ?? '',
            'active' => (bool) $record->user_active && (bool) $record->membership_active,
        ];
    }
}
