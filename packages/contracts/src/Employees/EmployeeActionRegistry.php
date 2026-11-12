<?php

namespace Kintai\Contracts\Employees;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'extension (depuis 1.5) : les bundles y déclarent des actions affichées sur chaque ligne de la liste des
 * employés et sur la fiche, par `BundleServiceProvider::employeeActions()` (sans effet tant que le bundle n'est pas
 * activé). Le Core implémente le registre.
 */
interface EmployeeActionRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(EmployeeAction $action): void;

    public function has(string $key): bool;

    /**
     * Actions visibles pour cette personne sur cet employé, triées par ordre puis par enregistrement.
     *
     * @return list<EmployeeAction>
     */
    public function actionsFor(Authenticatable $viewer, int $employeeId): array;
}
