<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * `documents.*` mélangeait deux bundles sans rapport (HiringReport et
 * ResignationReport) : un rôle ne pouvait pas accorder l'un sans l'autre.
 * Remplacé par deux catégories dédiées `hiring_reports.*` et
 * `resignation_reports.*` (voir PermissionCatalog) — même logique que le
 * découpage `photos.*` de la migration précédente.
 *
 * Pour chaque rôle existant, accorde `hiring_reports.<action>` ET
 * `resignation_reports.<action>` en miroir de chaque `documents.<action>`
 * déjà détenue (les deux bundles étaient gouvernés ensemble jusqu'ici, donc
 * préserver l'accès exact signifie accorder les deux), puis supprime les
 * lignes `documents.*` devenues obsolètes — la catégorie n'existe plus dans
 * PermissionCatalog, une ligne orpheline ne serait plus jamais lue.
 *
 * Idempotente : n'insère que les clés manquantes (une install fraîche les
 * reçoit déjà via le seed de MANAGER_DEFAULTS).
 */
return new class($this->capsule) extends Migration {

    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function up(): void
    {
        $conn = $this->capsule->getConnection();

        foreach (self::ACTIONS as $action) {
            $roleIds = $conn->table('role_permissions')
                ->where('permission_key', "documents.$action")
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                foreach (['hiring_reports', 'resignation_reports'] as $category) {
                    $key = "$category.$action";
                    $alreadyGranted = $conn->table('role_permissions')
                        ->where('role_id', $roleId)
                        ->where('permission_key', $key)
                        ->exists();

                    if (!$alreadyGranted) {
                        $conn->table('role_permissions')->insert([
                            'role_id'        => (int) $roleId,
                            'permission_key' => $key,
                        ]);
                    }
                }
            }
        }

        $conn->table('role_permissions')
            ->where('permission_key', 'like', 'documents.%')
            ->delete();
    }

    public function down(): void
    {
        // Découpage de catégorie : pas de rollback fidèle possible (on ne
        // sait plus reconstituer les lignes documents.* d'origine une fois
        // supprimées, et les deux nouvelles catégories peuvent avoir divergé
        // depuis via l'UI des rôles).
    }
};
