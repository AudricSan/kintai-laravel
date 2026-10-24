<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Validation\ColorRule;

/**
 * Avant le 15/10/2026, la couleur d'un compte, d'un type de shift ou d'un rôle était enregistrée telle
 * quelle, sans contrôle de format : une valeur forgée (« red;position:fixed;… ») finissait dans un attribut
 * style="" des vues. La saisie est maintenant limitée à #RGB / #RRGGBB (ColorRule) ; cette migration
 * remplace ce qui est déjà en base et ne respecte pas ce format par la couleur par défaut du champ
 * (null pour les rôles, dont la couleur est facultative).
 */
return new class($this->capsule) extends Migration {
    private const DEFAULT_COLOR = '#3B82F6';

    public function up(): void
    {
        $schema = $this->schema();
        $db = $this->capsule->getConnection();

        foreach (['users' => self::DEFAULT_COLOR, 'shift_types' => self::DEFAULT_COLOR, 'roles' => null] as $table => $fallback) {
            if (!$schema->hasTable($table) || !$schema->hasColumn($table, 'color')) {
                continue;
            }

            foreach ($db->table($table)->whereNotNull('color')->get(['id', 'color']) as $row) {
                if (!ColorRule::isValid($row->color)) {
                    $db->table($table)->where('id', $row->id)->update(['color' => $fallback]);
                }
            }
        }
    }

    public function down(): void
    {
        // Irréversible : la valeur d'origine n'est pas conservée.
    }
};
