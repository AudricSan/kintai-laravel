<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * shift_type_stores (backfillée en 2026_08_04_000002) est désormais la seule
 * source de vérité pour "quel(s) store(s) ce type de shift concerne-t-il" :
 * store_id (et l'unicité ['store_id','code'] qui allait avec — plus de
 * contrainte d'unicité de code, décision explicite : un même code peut
 * réapparaître librement) disparaît de shift_types.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasColumn('shift_types', 'store_id')) {
            return;
        }
        $this->dropStoreIdConstraints();
        $this->schema()->table('shift_types', function (Blueprint $table) {
            $table->dropColumn('store_id');
        });
    }

    public function down(): void
    {
        if ($this->schema()->hasColumn('shift_types', 'store_id')) {
            return;
        }
        // Restaure une colonne nullable, sans réinjecter de valeur ni
        // reconstruire l'unicité ['store_id','code'] : un type peut désormais
        // couvrir plusieurs stores (shift_type_stores), il n'y a plus un
        // store "propriétaire" unique et sans ambiguïté à réattribuer.
        $this->schema()->table('shift_types', function (Blueprint $table) {
            $table->integer('store_id')->nullable()->after('id');
        });
    }

    /**
     * Sur une installation plus ancienne (bootstrap SQL legacy), l'index
     * couvrant store_id peut porter un nom différent de celui qu'Illuminate
     * déduit conventionnellement — voir 2026_07_15_000004 pour le même
     * problème sur employee_feedbacks : on retrouve les index par
     * introspection plutôt que par nom supposé, sinon dropColumn('store_id')
     * échoue (SQLite refuse de retirer une colonne encore indexée).
     */
    private function dropStoreIdConstraints(): void
    {
        // MySQL refuse de supprimer un index dont une clé étrangère a besoin (erreur 1553) : la clé d'abord.
        // SQLite garde l'ordre historique (index puis clé), dont dépend la reconstruction de la table.
        $mysql = $this->capsule->getConnection()->getDriverName() === 'mysql';
        if ($mysql) {
            $this->schema()->table('shift_types', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
            });
        }
        foreach ($this->schema()->getIndexes('shift_types') as $index) {
            if (in_array('store_id', $index['columns'], true)) {
                $this->schema()->table('shift_types', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            }
        }
        if (!$mysql) {
            $this->schema()->table('shift_types', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
            });
        }
    }
};
