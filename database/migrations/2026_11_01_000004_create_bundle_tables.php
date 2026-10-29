<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registres de bundles et bundles installés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_registries', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('url')->unique();
            $table->boolean('is_official')->default(false);
            $table->timestamp('created_at', 0)->nullable();
        });

        Schema::create('installed_bundles', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('active_version');
            $table->string('source_registry_url')->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_bundles');
        Schema::dropIfExists('bundle_registries');
    }
};
