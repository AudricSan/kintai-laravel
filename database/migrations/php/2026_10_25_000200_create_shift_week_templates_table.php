<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Modèles de semaine (WeekPlanner) : une semaine type d'un magasin, enregistrée sous un nom et réappliquée sur
 * n'importe quelle semaine. entries est la liste JSON des shifts du modèle (jour 0 à 6, employé, horaires, pause, note).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shift_week_templates')) {
            return;
        }
        $this->schema()->create('shift_week_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('name', 100);
            $table->text('entries');
            $table->integer('created_by')->nullable();
            $table->timestamps();

            $table->index('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shift_week_templates');
    }
};
