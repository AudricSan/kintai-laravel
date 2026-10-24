<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Le bundle StorePhoto réutilisait jusqu'ici la catégorie `documents.*`
 * (partagée avec les rapports d'embauche/démission) faute de catégorie
 * dédiée. `photos.*` (view/create/update/delete) devient sa propre
 * catégorie RBAC pour distinguer finement "qui gère les photos" de "qui
 * gère les documents RH" — voir PermissionCatalog::CATEGORY_BUNDLES.
 *
 * Pour tout rôle existant, accorde la clé `photos.<action>` en miroir de
 * chaque `documents.<action>` déjà détenue, afin de préserver exactement
 * l'accès aux photos qu'un rôle personnalisé avait avant ce changement
 * (c'est littéralement ce qui gouvernait /admin/photos jusqu'ici).
 *
 * Idempotente : n'insère que les clés manquantes. Ne fait rien sur une installation
 * neuve : les droits d'un bundle y sont accordés au rôle Manager à son installation.
 */
return new class($this->capsule) extends Migration {

    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function up(): void
    {
        $conn = $this->capsule->getConnection();
        // Installation neuve (aucun compte encore créé) : rien à rattraper ; les droits d'un bundle sont accordés au
        // rôle Manager à son installation (BundlePermissionGranter).
        if ($conn->table('users')->count() === 0) {
            return;
        }

        foreach (self::ACTIONS as $action) {
            $roleIds = $conn->table('role_permissions')
                ->where('permission_key', "documents.$action")
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                $alreadyGranted = $conn->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_key', "photos.$action")
                    ->exists();

                if (!$alreadyGranted) {
                    $conn->table('role_permissions')->insert([
                        'role_id'        => (int) $roleId,
                        'permission_key' => "photos.$action",
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->capsule->getConnection()
            ->table('role_permissions')
            ->where('permission_key', 'like', 'photos.%')
            ->delete();
    }
};
