<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface RoleRepositoryInterface
{
    public function findAll(): array;

    public function findById(int $id): ?array;

    public function findBySlug(string $slug): ?array;

    /**
     * Crée ou met à jour un rôle. Crée si pas d'ID, met à jour sinon.
     */
    public function save(array $data): array;

    /**
     * Supprime un rôle ainsi que ses permissions et affectations associées
     * (SQLite n'applique pas onDelete('cascade') ici, donc le nettoyage des
     * lignes dépendantes est fait explicitement).
     * @return int Nombre de lignes "roles" supprimées (0 ou 1).
     */
    public function delete(int $id): int;

    /** @return string[] Clés de permission accordées à ce rôle. */
    public function getPermissions(int $roleId): array;

    /** @return string[] Sous-ensemble de getPermissions() dont la portée est 'global'. */
    public function getGlobalPermissionKeys(int $roleId): array;

    /**
     * @param string[] $permissionKeys
     * @param string[] $globalScopeKeys Sous-ensemble de $permissionKeys à marquer en portée
     *                                  'global' (toutes les boutiques, quelle que soit la
     *                                  portée de l'affectation du rôle). Toute entrée absente
     *                                  de $permissionKeys est ignorée.
     */
    public function savePermissions(int $roleId, array $permissionKeys, array $globalScopeKeys = []): void;
}
