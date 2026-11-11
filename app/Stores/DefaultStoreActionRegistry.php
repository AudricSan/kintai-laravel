<?php

namespace App\Stores;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Kintai\Contracts\Stores\StoreAction;
use Kintai\Contracts\Stores\StoreActionRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat).
 */
class DefaultStoreActionRegistry implements StoreActionRegistry
{
    /** @var array<string, StoreAction> */
    private array $actions = [];

    public function register(StoreAction $action): void
    {
        if (isset($this->actions[$action->key])) {
            throw new InvalidArgumentException("Store action [{$action->key}] is already registered.");
        }

        $this->actions[$action->key] = $action;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function actionsFor(Authenticatable $viewer, int $storeId): array
    {
        $actions = array_values(array_filter(
            $this->actions,
            fn (StoreAction $action): bool => $action->isVisibleTo($viewer, $storeId),
        ));

        usort($actions, fn (StoreAction $a, StoreAction $b): int => $a->order <=> $b->order);

        return $actions;
    }
}
