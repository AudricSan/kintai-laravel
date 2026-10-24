<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Backfill automatique de role_assignments à partir des colonnes historiques
 * (users.is_admin, store_user.role), phase 3 de la refonte RBAC
 * (task/mermission.md). Exécutée comme migration — pas seulement comme le
 * script manuel scripts/migrate-roles.php — pour garantir qu'une instance
 * existante ne se retrouve jamais avec AuthService (qui lit désormais
 * role_assignments) sans aucune donnée : ce serait un verrouillage total hors
 * de l'admin panel pour tous les Owners/Managers existants au déploiement.
 * Idempotente (peut être ré-exécutée sans dupliquer, s'appuie sur
 * RoleAssignmentRepositoryInterface::assign()).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $conn = $this->capsule->getConnection();

        if (!$conn->getSchemaBuilder()->hasTable('roles') || !$conn->getSchemaBuilder()->hasTable('role_assignments')) {
            return;
        }

        $ownerRoleId    = $conn->table('roles')->where('slug', 'owner')->value('id');
        $managerRoleId  = $conn->table('roles')->where('slug', 'manager')->value('id');
        $employeeRoleId = $conn->table('roles')->where('slug', 'employee')->value('id');

        if ($ownerRoleId === null || $managerRoleId === null || $employeeRoleId === null) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Un nouveau query builder à chaque appel : Illuminate\Database\Query\Builder
        // mute son propre état sur where() (fluent, pas immutable) — réutiliser la
        // même instance à travers les itérations d'une boucle accumule les
        // conditions des passages précédents, rendant les vérifications suivantes
        // toujours fausses (found via test manuel : voir non-régression associée).
        $existsAssignment = function (int $userId, int $roleId, string $scopeType, ?int $scopeId) use ($conn): bool {
            $query = $conn->table('role_assignments')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->where('scope_type', $scopeType);
            return ($scopeId === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scopeId))->exists();
        };

        foreach ($conn->table('users')->where('is_admin', 1)->get(['id']) as $user) {
            $userId = (int) $user->id;
            if (!$existsAssignment($userId, (int) $ownerRoleId, 'global', null)) {
                $conn->table('role_assignments')->insert([
                    'user_id' => $userId, 'role_id' => (int) $ownerRoleId,
                    'scope_type' => 'global', 'scope_id' => null, 'created_at' => $now,
                ]);
            }
        }

        foreach ($conn->table('store_user')->get(['user_id', 'store_id', 'role']) as $membership) {
            $userId  = (int) $membership->user_id;
            $storeId = (int) $membership->store_id;
            $roleId  = in_array($membership->role, ['admin', 'manager'], true) ? (int) $managerRoleId : (int) $employeeRoleId;
            if (!$existsAssignment($userId, $roleId, 'store', $storeId)) {
                $conn->table('role_assignments')->insert([
                    'user_id' => $userId, 'role_id' => $roleId,
                    'scope_type' => 'store', 'scope_id' => $storeId, 'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Backfill de données : pas de rollback (les affectations créées ici
        // sont indiscernables d'affectations créées par ailleurs depuis).
    }
};
