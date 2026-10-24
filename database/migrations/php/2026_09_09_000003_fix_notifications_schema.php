<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Sur les installations créées avant le passage au système de migrations PHP,
 * la table notifications a été créée par l'ancien create_notifications_table.sql
 * avec les colonnes reference_id/body/is_read. 2026_06_09_000012 (nouveau schéma
 * data/read_at, voir DatabaseNotificationRepository) fait un hasTable() et ne
 * s'applique donc jamais sur ces bases : chaque notify() y échoue avec
 * "no such column: data" (ex. soumission d'un rapport journalier). Convertit le
 * schéma legacy vers le schéma attendu en conservant les données existantes.
 *
 * Idempotente et reprenable : chaque étape vérifie son propre état avant d'agir,
 * pour pouvoir rejouer proprement après un échec partiel (ex. is_read protégée
 * par un index composite legacy, à supprimer avant de pouvoir dropper la colonne).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $schema = $this->schema();

        // Seul is_read signe le schéma legacy : le schéma actuel (2026_06_09_000012, refonte d'octobre 2026) a de
        // nouveau des colonnes body/reference_id, qu'il ne faut surtout pas convertir.
        if (!$schema->hasColumn('notifications', 'is_read')) {
            return;
        }

        if (!$schema->hasColumn('notifications', 'data')) {
            $schema->table('notifications', function (Blueprint $table) {
                $table->text('data')->nullable();
            });
        }
        if (!$schema->hasColumn('notifications', 'read_at')) {
            $schema->table('notifications', function (Blueprint $table) {
                $table->timestamp('read_at')->nullable();
            });
        }

        $conn = $this->capsule->getConnection();
        $rows = $conn->table('notifications')->whereNull('data')
            ->get(['id', 'reference_id', 'body', 'is_read', 'created_at']);
        foreach ($rows as $row) {
            $conn->table('notifications')->where('id', $row->id)->update([
                'data'    => json_encode([
                    'body'         => $row->body ?? '',
                    'reference_id' => $row->reference_id !== null ? (int) $row->reference_id : null,
                ], JSON_UNESCAPED_UNICODE),
                'read_at' => $row->is_read ? $row->created_at : null,
            ]);
        }

        // is_read peut être couverte par un index composite legacy (ex. idx_notifications_user
        // sur user_id+is_read) : SQLite comme MySQL refusent de dropper une colonne indexée,
        // il faut d'abord retirer tout index qui la référence.
        foreach ($schema->getIndexes('notifications') as $index) {
            if (in_array('is_read', $index['columns'], true)) {
                $schema->table('notifications', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            }
        }

        $toDrop = array_values(array_filter(
            ['reference_id', 'body', 'is_read'],
            fn(string $c) => $schema->hasColumn('notifications', $c)
        ));
        if ($toDrop !== []) {
            $schema->table('notifications', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn($toDrop);
            });
        }

        if (!$schema->hasIndex('notifications', ['user_id', 'read_at'])) {
            $schema->table('notifications', function (Blueprint $table) {
                $table->index(['user_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        // Migration correctrice de schéma : pas de rollback (même convention que
        // 2026_07_15_000002_fix_employee_feedbacks_schema.php).
    }
};
