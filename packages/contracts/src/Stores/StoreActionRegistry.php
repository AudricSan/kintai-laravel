<?php

namespace Kintai\Contracts\Stores;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'extension (depuis 1.7) : les bundles y déclarent des actions affichées sur chaque ligne de la liste des
 * magasins, par `BundleServiceProvider::storeActions()` (sans effet tant que le bundle n'est pas activé). Le Core
 * implémente le registre.
 */
interface StoreActionRegistry
{
    /**
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(StoreAction $action): void;

    public function has(string $key): bool;

    /**
     * Actions visibles pour cette personne sur ce magasin, triées par ordre puis par enregistrement.
     *
     * @return list<StoreAction>
     */
    public function actionsFor(Authenticatable $viewer, int $storeId): array;
}
