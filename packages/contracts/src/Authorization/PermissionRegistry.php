<?php

namespace Kintai\Contracts\Authorization;

/**
 * Catalogue des permissions : le Core et les bundles y déclarent leurs catégories (`catégorie.action`, par exemple
 * `timeoff.view`). Une permission déclarée devient une capacité du Gate et une case du formulaire de rôle.
 */
interface PermissionRegistry
{
    /**
     * @param  list<string>  $actions
     *
     * @throws \InvalidArgumentException Catégorie ou action invalide, catégorie déjà enregistrée.
     */
    public function register(string $category, array $actions): void;

    /**
     * @return array<string, list<string>>
     */
    public function categories(): array;

    /**
     * @return list<string>
     */
    public function all(): array;

    public function has(string $key): bool;
}
