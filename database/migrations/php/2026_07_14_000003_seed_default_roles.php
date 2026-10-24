<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Auth\PermissionCatalog;

/**
 * Seede les 3 rôles par défaut (Owner/Manager/Employé). Purement additif :
 * ces rôles ne sont encore lus par aucun code applicatif à ce stade
 * (AuthService continue de lire users.is_admin / store_user.role). Exécutée
 * comme une migration (pas un seed SQL optionnel) car ces lignes sont des
 * données structurelles nécessaires, pas des données de démo — elles doivent
 * exister aussi bien sur une install fraîche que sur une instance existante
 * mise à jour via scripts/db-migrate.php.
 */
return new class($this->capsule) extends Migration {
    public function isSeed(): bool
    {
        return true;
    }

    public function up(): void
    {
        $conn = $this->capsule->getConnection();
        $roles = $conn->table('roles');

        if ($roles->where('slug', 'owner')->exists()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Owner est un rôle système : PermissionService lui accorde toutes les
        // permissions implicitement (is_system=1), aucune ligne role_permissions
        // n'est nécessaire pour lui.
        $roles->insert([
            'name'        => 'Owner',
            'slug'        => 'owner',
            'description' => 'Propriétaire de l\'organisation. Tous les droits, rôle non modifiable.',
            'is_system'   => 1,
            'created_at'  => $now,
        ]);

        $managerId = $roles->insertGetId([
            'name'        => 'Manager',
            'slug'        => 'manager',
            'description' => 'Gestion d\'un magasin : employés, plannings, documents, paie.',
            'is_system'   => 0,
            'created_at'  => $now,
        ]);

        // Employé : aucune permission de gestion par défaut, aucune ligne
        // role_permissions nécessaire.
        $roles->insert([
            'name'        => 'Employé',
            'slug'        => 'employee',
            'description' => 'Accès employé standard, sans droits de gestion.',
            'is_system'   => 0,
            'created_at'  => $now,
        ]);

        $rolePermissions = $conn->table('role_permissions');
        foreach (PermissionCatalog::MANAGER_DEFAULTS as $key) {
            $rolePermissions->insert(['role_id' => $managerId, 'permission_key' => $key]);
        }
    }

    public function down(): void
    {
        $conn = $this->capsule->getConnection();
        $roleIds = $conn->table('roles')->whereIn('slug', ['owner', 'manager', 'employee'])->pluck('id');
        $conn->table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        $conn->table('roles')->whereIn('slug', ['owner', 'manager', 'employee'])->delete();
    }
};
