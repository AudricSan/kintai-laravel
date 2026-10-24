<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Accorde les nouvelles clés de permission des bundles (timeoff, swaps,
 * timeclock, open_shifts, feedbacks) aux rôles existants qui détiennent déjà
 * `shifts.update` (proxy « rôle gestionnaire »). Avant cette migration, ces
 * pages/endpoints n'étaient soumis qu'au filtre grossier d'AdminMiddleware :
 * sans ce backfill, mapper les routes des bundles sur ces nouvelles clés
 * retirerait l'accès aux managers existants. Le Owner peut ensuite affiner
 * rôle par rôle via /admin/roles.
 *
 * Idempotente : n'insère que les clés manquantes. Ne fait rien sur une installation
 * neuve : les droits d'un bundle y sont accordés au rôle Manager à son installation.
 */
return new class($this->capsule) extends Migration {

    private const NEW_KEYS = [
        'timeoff.view', 'timeoff.create', 'timeoff.update', 'timeoff.approve', 'timeoff.delete',
        'swaps.view', 'swaps.create', 'swaps.update', 'swaps.approve', 'swaps.delete',
        'timeclock.view', 'timeclock.update', 'timeclock.delete',
        'open_shifts.view', 'open_shifts.publish', 'open_shifts.approve',
        'feedbacks.view', 'feedbacks.update', 'feedbacks.delete',
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
