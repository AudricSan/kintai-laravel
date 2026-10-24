<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Refonte des notifications (octobre 2026) : aligne les instances existantes sur le schéma de
 * 2026_06_09_000012. La colonne JSON `data` (body, reference_id, link) est éclatée en vraies colonnes, et
 * body_key/params/reference_type sont ajoutées. Les lignes existantes gardent body_key à NULL : leur texte
 * figé (body) reste affiché tel quel.
 *
 * Sans effet sur une instance neuve (pas de colonne data). Idempotente et reprenable : chaque étape vérifie
 * son propre état avant d'agir.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        if (!$schema->hasTable('notifications') || !$schema->hasColumn('notifications', 'data')) {
            return;
        }

        $columns = [
            'body_key'       => fn(Blueprint $t) => $t->string('body_key')->nullable(),
            'params'         => fn(Blueprint $t) => $t->text('params')->nullable(),
            'body'           => fn(Blueprint $t) => $t->text('body')->nullable(),
            'link'           => fn(Blueprint $t) => $t->string('link', 500)->nullable(),
            'reference_type' => fn(Blueprint $t) => $t->string('reference_type', 40)->nullable(),
            'reference_id'   => fn(Blueprint $t) => $t->integer('reference_id')->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (!$schema->hasColumn('notifications', $name)) {
                $schema->table('notifications', function (Blueprint $table) use ($define) {
                    $define($table);
                });
            }
        }

        // Copie par lots : une instance ancienne peut avoir accumulé beaucoup de notifications.
        $conn = $this->capsule->getConnection();
        $conn->table('notifications')->whereNotNull('data')->orderBy('id')->chunkById(500, function ($rows) use ($conn) {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->data, true);
                $decoded = is_array($decoded) ? $decoded : [];
                $link    = isset($decoded['link']) && is_string($decoded['link']) && $decoded['link'] !== '' ? $decoded['link'] : null;
                $refId   = isset($decoded['reference_id']) && is_numeric($decoded['reference_id']) ? (int) $decoded['reference_id'] : null;
                $conn->table('notifications')->where('id', $row->id)->update([
                    'body'         => (string) ($decoded['body'] ?? ''),
                    'link'         => $link !== null ? mb_substr($link, 0, 500) : null,
                    'reference_id' => $refId,
                ]);
            }
        });

        $schema->table('notifications', function (Blueprint $table) {
            $table->dropColumn('data');
        });

        if (!$schema->hasIndex('notifications', ['user_id', 'id'])) {
            $schema->table('notifications', function (Blueprint $table) {
                $table->index(['user_id', 'id']);
            });
        }
        if (!$schema->hasIndex('notifications', ['user_id', 'reference_type', 'reference_id'])) {
            $schema->table('notifications', function (Blueprint $table) {
                $table->index(['user_id', 'reference_type', 'reference_id']);
            });
        }
    }

    public function down(): void
    {
        // Migration de schéma à sens unique (même convention que 2026_09_09_000003_fix_notifications_schema.php).
    }
};
