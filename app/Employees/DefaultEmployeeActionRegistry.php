<?php

namespace App\Employees;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Kintai\Contracts\Employees\EmployeeAction;
use Kintai\Contracts\Employees\EmployeeActionRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat).
 */
class DefaultEmployeeActionRegistry implements EmployeeActionRegistry
{
    /** @var array<string, EmployeeAction> */
    private array $actions = [];

    public function register(EmployeeAction $action): void
    {
        if (isset($this->actions[$action->key])) {
            throw new InvalidArgumentException("Employee action [{$action->key}] is already registered.");
        }

        $this->actions[$action->key] = $action;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function actionsFor(Authenticatable $viewer, int $employeeId): array
    {
        $actions = array_values(array_filter(
            $this->actions,
            fn (EmployeeAction $action): bool => $action->isVisibleTo($viewer, $employeeId),
        ));

        usort($actions, fn (EmployeeAction $a, EmployeeAction $b): int => $a->order <=> $b->order);

        return $actions;
    }
}
