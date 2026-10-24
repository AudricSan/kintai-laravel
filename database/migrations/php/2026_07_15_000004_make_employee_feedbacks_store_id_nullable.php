<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * L'Owner (et tout rôle global sans adhésion à un store — voir install.php,
 * qui n'assigne que role_assignments, pas de ligne store_user) ne peut être
 * rattaché à aucun store : le feedback modal étant désormais accessible
 * aussi côté admin/owner (voir FeedbackController::submit()), store_id doit
 * pouvoir rester vide pour ces comptes plutôt que de bloquer la soumission.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->dropStoreIdForeignAndIndex();
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['store_id']);
        });
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->integer('store_id')->nullable()->after('user_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->dropStoreIdForeignAndIndex();
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['store_id']);
        });
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->integer('store_id')->after('user_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Sur une installation plus ancienne (bootstrap SQL legacy plutôt que
     * migrations Eloquent depuis le début), l'index sur store_id peut porter
     * un nom différent de celui qu'Illuminate déduit de
     * ['store_id', 'created_at'] (ex. observé : "idx_employee_feedback_store").
     * dropIndex(['store_id', 'created_at']) compile un DROP INDEX sur ce nom
     * conventionnel et échoue avec "no such index" si le vrai nom diffère —
     * et le dropColumn('store_id') qui suit échoue à son tour (SQLite refuse
     * de retirer une colonne encore référencée par un index). Il faut donc
     * retrouver et supprimer le/les index couvrant store_id par introspection
     * plutôt que par le nom conventionnel supposé.
     *
     * dropForeign(['store_id']), lui, n'a pas ce problème : passé comme
     * tableau de colonnes (pas un nom), Illuminate retrouve la contrainte par
     * ses colonnes pour reconstruire la table SQLite sans elle — c'est un nom
     * de contrainte littéral qu'il ne sait pas traiter sur ce driver
     * ("This database driver does not support dropping foreign keys by name").
     */
    private function dropStoreIdForeignAndIndex(): void
    {
        // MySQL refuse de supprimer un index dont une clé étrangère a besoin (erreur 1553) : la clé d'abord.
        // SQLite garde l'ordre historique (index puis clé), dont dépend la reconstruction de la table.
        $mysql = $this->capsule->getConnection()->getDriverName() === 'mysql';
        if ($mysql) {
            $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
            });
        }
        foreach ($this->schema()->getIndexes('employee_feedbacks') as $index) {
            if (in_array('store_id', $index['columns'], true)) {
                $this->schema()->table('employee_feedbacks', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            }
        }
        if (!$mysql) {
            $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
            });
        }
    }
};
