<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Table de suivi des migrations fournies par les bundles distribués (dossier
 * optionnel `database/migrations/` à la racine d'un bundle — voir
 * BundleMigrationRunner et wiki : Creating-a-Bundle). Séparée de `migrations`
 * (réservée au Core) pour ne jamais risquer de collision de nom entre bundles
 * et permettre un nettoyage ciblé par bundle_slug lors d'un uninstall.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('bundle_migrations')) {
            return;
        }
        $this->schema()->create('bundle_migrations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bundle_slug');
            $table->string('migration');
            $table->timestamp('executed_at')->useCurrent();
            $table->unique(['bundle_slug', 'migration']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('bundle_migrations');
    }
};
