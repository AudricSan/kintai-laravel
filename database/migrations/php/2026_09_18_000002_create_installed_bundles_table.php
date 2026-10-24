<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Une ligne par bundle installé dynamiquement (storage/bundles/), la
 * décision métier (quelle version est active) que l'Owner a prise. Ne pas
 * confondre avec storage/bundles/installed.json, un simple miroir filesystem
 * régénérable depuis cette table — voir InstalledBundleManifestStore.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('installed_bundles')) {
            return;
        }
        $this->schema()->create('installed_bundles', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('active_version');
            $table->string('source_registry_url')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('installed_bundles');
    }
};
