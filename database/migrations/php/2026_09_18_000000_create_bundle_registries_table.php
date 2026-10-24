<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Registries de bundles (façon dépôts d'add-ons Home Assistant) : chacun est
 * une URL de listing JSON que l'Owner peut ajouter depuis /admin/bundles pour
 * découvrir des bundles installables au-delà de ceux du monorepo. Voir
 * BundleRegistryClient pour le format consommé et
 * 2026_09_18_000001_seed_official_bundle_registry.php pour le registry
 * officiel Kintai, ajouté par défaut.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('bundle_registries')) {
            return;
        }
        $this->schema()->create('bundle_registries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('url')->unique();
            $table->boolean('is_official')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('bundle_registries');
    }
};
