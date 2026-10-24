<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Une ligne shift_type_stores par type existant, reprenant son store_id
 * actuel — exécutée avant que 2026_08_04_000003 ne supprime cette colonne.
 * Idempotente (insertOrIgnore sur la clé composite).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $conn = $this->capsule->getConnection();

        if (!$conn->getSchemaBuilder()->hasTable('shift_types') || !$conn->getSchemaBuilder()->hasTable('shift_type_stores')) {
            return;
        }
        if (!$conn->getSchemaBuilder()->hasColumn('shift_types', 'store_id')) {
            return;
        }

        foreach ($conn->table('shift_types')->get(['id', 'store_id']) as $type) {
            $conn->table('shift_type_stores')->insertOrIgnore([
                'shift_type_id' => (int) $type->id,
                'store_id'      => (int) $type->store_id,
            ]);
        }
    }

    public function down(): void
    {
        // Backfill de données : pas de rollback (voir 2026_07_14_000004_backfill_role_assignments
        // pour la même convention — les lignes créées ici sont indiscernables de lignes
        // créées depuis par l'application).
    }
};
