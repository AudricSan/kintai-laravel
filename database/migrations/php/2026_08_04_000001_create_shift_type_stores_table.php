<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Un type de shift peut désormais être activé sur plusieurs stores (table
 * pivot), remplaçant l'ancien couplage 1-1 shift_types.store_id — voir
 * migration suivante (backfill) puis 2026_08_04_000003 (suppression de la
 * colonne). Même convention que role_permissions (clé composite, pas de
 * surrogate id : c'est un simple on/off, pas de métadonnée par affectation).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shift_type_stores')) {
            return;
        }
        $this->schema()->create('shift_type_stores', function (Blueprint $table) {
            $table->integer('shift_type_id');
            $table->integer('store_id');

            $table->primary(['shift_type_id', 'store_id']);
            $table->foreign('shift_type_id')->references('id')->on('shift_types')->onDelete('cascade');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shift_type_stores');
    }
};
