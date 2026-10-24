<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Extension\Navigation;

/**
 * La barre mobile utilise désormais les mêmes clés que la barre du haut (kintai\Core\Extension\Navigation). Les choix
 * déjà enregistrés sous les anciennes clés (gérant : team, requests ; employé : my_planning, timeclock, my_timeoff)
 * sont convertis pour que la barre de chacun reste la même.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        if (!$schema->hasTable('user_nav_prefs') || !$schema->hasColumn('user_nav_prefs', 'bottom_nav_items')) {
            return;
        }

        $db = $this->capsule->getConnection();
        foreach ($db->table('user_nav_prefs')->whereNotNull('bottom_nav_items')->get(['id', 'bottom_nav_items']) as $row) {
            $items = json_decode((string) $row->bottom_nav_items, true);
            if (!is_array($items)) {
                continue;
            }
            $migrated = Navigation::migrateLegacyMobileKeys($items);
            if ($migrated !== array_values($items)) {
                $db->table('user_nav_prefs')->where('id', $row->id)->update(['bottom_nav_items' => json_encode($migrated)]);
            }
        }
    }

    public function down(): void
    {
        // Rien à défaire : les nouvelles clés sont les seules lues par la barre mobile.
    }
};
