<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Registres de bundles, bundles installés et migrations des bundles.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('bundle_registries', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'name', ['length' => 255]);
            $table->addColumn('string', 'url', ['length' => 255, 'unique' => true]);
            $table->addColumn('boolean', 'is_official', ['default' => false]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'nullable' => true]);
        });

        $this->schema()->create('installed_bundles', function (Blueprint $table) {
            $table->addColumn('string', 'slug', ['length' => 255, 'primary' => true]);
            $table->addColumn('string', 'active_version', ['length' => 255]);
            $table->addColumn('string', 'source_registry_url', ['length' => 255, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
        });

        $this->schema()->create('bundle_migrations', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'bundle_slug', ['length' => 255]);
            $table->addColumn('string', 'migration', ['length' => 255]);
            $table->addColumn('timestamp', 'executed_at', ['precision' => 0, 'useCurrent' => true]);

            $table->unique(['bundle_slug', 'migration'], 'bundle_migrations_bundle_slug_migration_unique');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('bundle_migrations');
        $this->schema()->dropIfExists('installed_bundles');
        $this->schema()->dropIfExists('bundle_registries');
    }
};
