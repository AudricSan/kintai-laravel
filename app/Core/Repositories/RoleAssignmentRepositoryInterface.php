<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface RoleAssignmentRepositoryInterface
{
    public function findById(int $id): ?array;

    /** @return array Toutes les affectations (rôle + portée) d'un utilisateur. */
    public function findByUser(int $userId): array;

    /** @return array Toutes les affectations d'un rôle donné, tous utilisateurs/portées confondus. */
    public function findByRole(int $roleId): array;

    /**
     * Toutes les affectations pour une portée donnée.
     * @param string $scopeType 'global' ou 'store'
     * @param int|null $scopeId store_id si scopeType='store', null si 'global'
     */
    public function findByScope(string $scopeType, ?int $scopeId): array;

    /**
     * Affecte un rôle à un utilisateur sur une portée donnée. Retourne null
     * si cette affectation exacte existe déjà (idempotent, comme
     * StoreService::addMember() pour store_user).
     */
    public function assign(int $userId, int $roleId, string $scopeType, ?int $scopeId): ?array;

    /** @return int Nombre de lignes supprimées (0 ou 1). */
    public function revoke(int $id): int;
}
