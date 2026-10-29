<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Auth\PermissionCatalog;
use kintai\Core\Database\Migration;

/**
 * Les trois rôles par défaut (Owner, Manager, Employé). Données structurelles nécessaires à toute instance, pas des
 * données de démonstration : exécutée comme une migration, et rejouée après une remise à zéro (isSeed()).
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

        // Owner est un rôle système : PermissionService lui accorde toutes les permissions implicitement (is_system = 1),
        // aucune ligne role_permissions n'est nécessaire pour lui.
        $roles->insert([
            'name'        => 'Owner',
            'slug'        => 'owner',
            'description' => 'Propriétaire de l\'organisation. Tous les droits, rôle non modifiable.',
            'is_system'   => 1,
            'is_manager'  => 0,
            'created_at'  => $now,
        ]);

        $managerId = $roles->insertGetId([
            'name'        => 'Manager',
            'slug'        => 'manager',
            'description' => 'Gestion d\'un magasin : employés, plannings, documents, paie.',
            'is_system'   => 0,
            'is_manager'  => 1,
            'created_at'  => $now,
        ]);

        // Employé : aucune permission de gestion par défaut.
        $roles->insert([
            'name'        => 'Employé',
            'slug'        => 'employee',
            'description' => 'Accès employé standard, sans droits de gestion.',
            'is_system'   => 0,
            'is_manager'  => 0,
            'created_at'  => $now,
        ]);

        $rolePermissions = $conn->table('role_permissions');
        foreach (PermissionCatalog::MANAGER_DEFAULTS as $key) {
            $rolePermissions->insert(['role_id' => $managerId, 'permission_key' => $key, 'scope' => 'local']);
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
