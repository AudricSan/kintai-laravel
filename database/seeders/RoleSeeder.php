<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use kintai\Core\Auth\PermissionCatalog;

/**
 * Les trois rôles par défaut (Owner, Manager, Employé). Owner est un rôle système : PermissionService lui accorde toutes
 * les permissions implicitement (is_system = 1), aucune ligne role_permissions n'est nécessaire pour lui.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('roles')->where('slug', 'owner')->exists()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        DB::table('roles')->insert([
            'name'        => 'Owner',
            'slug'        => 'owner',
            'description' => 'Propriétaire de l\'organisation. Tous les droits, rôle non modifiable.',
            'is_system'   => 1,
            'is_manager'  => 0,
            'created_at'  => $now,
        ]);

        $managerId = DB::table('roles')->insertGetId([
            'name'        => 'Manager',
            'slug'        => 'manager',
            'description' => 'Gestion d\'un magasin : employés, plannings, documents, paie.',
            'is_system'   => 0,
            'is_manager'  => 1,
            'created_at'  => $now,
        ]);

        // Employé : aucune permission de gestion par défaut.
        DB::table('roles')->insert([
            'name'        => 'Employé',
            'slug'        => 'employee',
            'description' => 'Accès employé standard, sans droits de gestion.',
            'is_system'   => 0,
            'is_manager'  => 0,
            'created_at'  => $now,
        ]);

        foreach (PermissionCatalog::MANAGER_DEFAULTS as $key) {
            DB::table('role_permissions')->insert(['role_id' => $managerId, 'permission_key' => $key, 'scope' => 'local']);
        }
    }
}
