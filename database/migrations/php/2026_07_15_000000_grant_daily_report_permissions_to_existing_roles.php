<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Accorde les nouvelles clés `daily_reports.*` aux rôles existants qui
 * détiennent déjà `shifts.update` (proxy « rôle gestionnaire »), même
 * principe que la migration 2026_07_14_000005 pour les autres bundles.
 *
 * DailyReportPermissionService gérait jusqu'ici ses propres droits via
 * `store_user.role` (staff/manager/admin) et des listes configurables par
 * store (`can_submit_roles`/`can_validate_roles`/`can_create_user_ids`) —
 * désormais remplacées par ce catalogue de permissions dynamique, avec
 * création/soumission de son propre brouillon toujours en libre-service
 * (voir DailyReportPermissionService), pour ne pas priver les employés sans
 * rôle géré de la possibilité de soumettre leurs propres rapports.
 *
 * Idempotente : n'insère que les clés manquantes. Ne fait rien sur une installation
 * neuve : les droits d'un bundle y sont accordés au rôle Manager à son installation.
 */
return new class($this->capsule) extends Migration {

    private const NEW_KEYS = [
        'daily_reports.view', 'daily_reports.create', 'daily_reports.update',
        'daily_reports.submit', 'daily_reports.approve', 'daily_reports.delete',
    ];

    public function up(): void
    {
        $conn = $this->capsule->getConnection();
        // Installation neuve (aucun compte encore créé) : rien à rattraper ; les droits d'un bundle sont accordés au
        // rôle Manager à son installation (BundlePermissionGranter).
        if ($conn->table('users')->count() === 0) {
            return;
        }

        $managerRoleIds = $conn->table('role_permissions')
            ->where('permission_key', 'shifts.update')
            ->pluck('role_id');

        foreach ($managerRoleIds as $roleId) {
            $existing = $conn->table('role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_key', self::NEW_KEYS)
                ->pluck('permission_key')
                ->all();

            foreach (array_diff(self::NEW_KEYS, $existing) as $key) {
                $conn->table('role_permissions')->insert([
                    'role_id'        => (int) $roleId,
                    'permission_key' => $key,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->capsule->getConnection()
            ->table('role_permissions')
            ->whereIn('permission_key', self::NEW_KEYS)
            ->delete();
    }
};
