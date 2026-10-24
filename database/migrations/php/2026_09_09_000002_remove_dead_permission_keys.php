<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * `settings.update`, `shifts.export` et `shifts.validate` étaient présentes
 * dans PermissionCatalog (et donc cochables dans l'écran de rôle) mais
 * n'étaient référencées par aucune route ni aucun contrôleur : les cocher ou
 * décocher n'avait strictement aucun effet, ce qui est trompeur pour un
 * Owner qui les prendrait pour de vraies restrictions. Retirées du
 * catalogue ; cette migration nettoie les lignes role_permissions
 * correspondantes, qui ne seront plus jamais lues.
 */
return new class($this->capsule) extends Migration {

    private const DEAD_KEYS = ['settings.update', 'shifts.export', 'shifts.validate'];

    public function up(): void
    {
        $this->capsule->getConnection()
            ->table('role_permissions')
            ->whereIn('permission_key', self::DEAD_KEYS)
            ->delete();
    }

    public function down(): void
    {
        // Nettoyage de données : pas de rollback (les lignes supprimées ne
        // gouvernaient de toute façon rien).
    }
};
