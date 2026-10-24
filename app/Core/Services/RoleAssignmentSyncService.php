<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;

/**
 * Applique les affectations de rôle dynamique (role_assignments) décidées par
 * les formulaires Owner/store (case "Owner", sélecteur de rôle par store) —
 * seule source de vérité pour l'autorisation depuis la bascule RBAC-V2
 * (task/mermission.md, phase 3). Les anciennes colonnes users.is_admin /
 * store_user.role ont été retirées en phase 4 ; ce service ne les écrit plus.
 */
final class RoleAssignmentSyncService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly RoleAssignmentRepositoryInterface $assignments,
    ) {}

    /**
     * Rôles proposables dans les sélecteurs de rôle par store : tous les rôles
     * dynamiques, hors rôles système (Owner s'assigne en portée globale, pas
     * par store). Chaque rôle est enrichi d'un flag `is_managing` (accorde au
     * moins une permission) pour l'affichage, sans coder de nom de rôle en dur.
     */
    public function assignableStoreRoles(): array
    {
        $out = [];
        foreach ($this->roles->findAll() as $role) {
            if (!empty($role['is_system'])) {
                continue;
            }
            $role['is_managing'] = $this->roles->getPermissions((int) $role['id']) !== [];
            $out[] = $role;
        }
        return $out;
    }

    /**
     * Tous les rôles (système inclus) enrichis du même flag `is_managing` —
     * pour le sélecteur "Rôle" unifié du formulaire de création d'utilisateur
     * (Owner + rôles par store dans une seule liste, au lieu d'un bascule
     * is_admin séparée du système de rôles dynamique).
     */
    public function allRoles(): array
    {
        $out = [];
        foreach ($this->roles->findAll() as $role) {
            $role['is_managing'] = !empty($role['is_system']) || $this->roles->getPermissions((int) $role['id']) !== [];
            $out[] = $role;
        }
        return $out;
    }

    /** Retourne un rôle par id, système inclus (contrairement à findAssignableRole), enrichi de `is_managing`. */
    public function findRole(int $roleId): ?array
    {
        if ($roleId <= 0) {
            return null;
        }
        $role = $this->roles->findById($roleId);
        if ($role === null) {
            return null;
        }
        $role['is_managing'] = !empty($role['is_system']) || $this->roles->getPermissions($roleId) !== [];
        return $role;
    }

    /** Retourne le rôle s'il existe et est assignable par store (non système), null sinon. */
    public function findAssignableRole(int $roleId): ?array
    {
        if ($roleId <= 0) {
            return null;
        }
        $role = $this->roles->findById($roleId);
        if ($role === null || !empty($role['is_system'])) {
            return null;
        }
        $role['is_managing'] = $this->roles->getPermissions($roleId) !== [];
        return $role;
    }

    /**
     * Rôle pré-sélectionné par défaut dans les formulaires : le premier rôle
     * sans permission de gestion (équivalent "employé"), sinon le premier
     * rôle assignable. Null si aucun rôle dynamique n'existe.
     */
    public function defaultStoreRole(): ?array
    {
        $assignable = $this->assignableStoreRoles();
        foreach ($assignable as $role) {
            if (empty($role['is_managing'])) {
                return $role;
            }
        }
        return $assignable[0] ?? null;
    }

    /**
     * Applique l'affectation d'un rôle précis (par id) sur un store : révoque
     * les autres affectations store-scope de l'utilisateur sur ce store, puis
     * crée l'affectation manquante. Retourne le rôle appliqué, ou null si le
     * rôle n'est pas assignable (inexistant ou système).
     */
    public function syncStoreRoleById(int $userId, int $storeId, int $roleId): ?array
    {
        $role = $this->findAssignableRole($roleId);
        if ($role === null) {
            return null;
        }

        $storeAssignments = array_values(array_filter(
            $this->assignments->findByUser($userId),
            fn($a) => $a['scope_type'] === 'store' && (int) $a['scope_id'] === $storeId,
        ));
        foreach ($storeAssignments as $a) {
            if ((int) $a['role_id'] !== $roleId) {
                $this->assignments->revoke((int) $a['id']);
            }
        }
        if (!in_array($roleId, array_map(fn($a) => (int) $a['role_id'], $storeAssignments), true)) {
            $this->assignments->assign($userId, $roleId, 'store', $storeId);
        }
        return $role;
    }

    /**
     * Map user_id → ['role_id', 'name', 'is_managing'] des rôles store-scope
     * assignés sur un store (affichage de la liste des membres). Si un
     * utilisateur cumule plusieurs rôles sur le store, le premier est retenu
     * pour le sélecteur et les noms sont concaténés pour l'affichage.
     */
    public function storeRoleMapForStore(int $storeId): array
    {
        return $this->buildRoleMap($this->assignments->findByScope('store', $storeId), 'user_id');
    }

    /** Map store_id → ['role_id', 'name', 'is_managing'] des rôles store-scope d'un utilisateur. */
    public function storeRoleMapForUser(int $userId): array
    {
        $storeAssignments = array_values(array_filter(
            $this->assignments->findByUser($userId),
            fn($a) => $a['scope_type'] === 'store' && $a['scope_id'] !== null,
        ));
        return $this->buildRoleMap($storeAssignments, 'scope_id');
    }

    /** @param array $assignments Lignes role_assignments. @param string $keyField Champ servant de clé de la map. */
    private function buildRoleMap(array $assignments, string $keyField): array
    {
        $roleCache = [];
        $map = [];
        foreach ($assignments as $a) {
            $roleId = (int) $a['role_id'];
            if (!array_key_exists($roleId, $roleCache)) {
                $role = $this->roles->findById($roleId);
                if ($role !== null) {
                    $role['is_managing'] = $this->roles->getPermissions($roleId) !== [];
                }
                $roleCache[$roleId] = $role;
            }
            $role = $roleCache[$roleId];
            if ($role === null) {
                continue;
            }
            $key = (int) $a[$keyField];
            if (!isset($map[$key])) {
                $map[$key] = [
                    'role_id'     => $roleId,
                    'name'        => (string) $role['name'],
                    'is_managing' => !empty($role['is_managing']),
                ];
            } else {
                $map[$key]['name'] .= ', ' . $role['name'];
                $map[$key]['is_managing'] = $map[$key]['is_managing'] || !empty($role['is_managing']);
            }
        }
        return $map;
    }

    /** Le rôle système Owner (slug 'owner'), pour afficher son vrai nom dans les formulaires plutôt qu'un libellé codé en dur. */
    public function ownerRole(): ?array
    {
        return $this->roles->findBySlug('owner');
    }

    /** Assigne ou retire le rôle Owner (portée globale) selon le flag users.is_admin posté. */
    public function syncOwnerRole(int $userId, bool $isAdmin): void
    {
        $ownerRole = $this->roles->findBySlug('owner');
        if ($ownerRole === null) {
            return;
        }
        $ownerRoleId = (int) $ownerRole['id'];
        $existing = array_values(array_filter(
            $this->assignments->findByUser($userId),
            fn($a) => $a['scope_type'] === 'global' && (int) $a['role_id'] === $ownerRoleId,
        ));

        if ($isAdmin) {
            if ($existing === []) {
                $this->assignments->assign($userId, $ownerRoleId, 'global', null);
            }
            return;
        }
        foreach ($existing as $a) {
            $this->assignments->revoke((int) $a['id']);
        }
    }

    /** Retire toute affectation de rôle store-scope pour cet utilisateur sur ce store. */
    public function revokeStoreRole(int $userId, int $storeId): void
    {
        foreach ($this->assignments->findByUser($userId) as $a) {
            if ($a['scope_type'] === 'store' && (int) $a['scope_id'] === $storeId) {
                $this->assignments->revoke((int) $a['id']);
            }
        }
    }
}
