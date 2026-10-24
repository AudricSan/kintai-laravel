<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Extension\Navigation;

/**
 * Les entrées masquées par un employé depuis l'onglet « navigation » de son profil étaient enregistrées sous des
 * clés que la barre du haut ne lisait pas (my_planning, timeclock, my_timeoff au lieu de shifts, timeclocks,
 * timeoff) : les masquer n'avait aucun effet. La navigation utilise désormais une seule clé par entrée (voir
 * kintai\Core\Extension\Navigation) ; on convertit les préférences déjà enregistrées pour qu'elles prennent effet.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('user_nav_prefs')) {
            return;
        }

        $db = $this->capsule->getConnection();
        foreach ($db->table('user_nav_prefs')->get(['id', 'hidden_items']) as $row) {
            $hidden = json_decode((string) $row->hidden_items, true);
            if (!is_array($hidden)) {
                continue;
            }
            $migrated = Navigation::migrateLegacyKeys($hidden);
            if ($migrated !== array_values($hidden)) {
                $db->table('user_nav_prefs')->where('id', $row->id)->update(['hidden_items' => json_encode($migrated)]);
            }
        }
    }

    public function down(): void
    {
        // Rien à défaire : les nouvelles clés sont les seules lues par la navigation.
    }
};
