<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Validation des feuilles de temps (TimesheetService) : une ligne par magasin et semaine validée, avec qui l'a validée,
 * quand, et l'empreinte des shifts et pointages à ce moment (une empreinte différente signale une semaine modifiée
 * depuis sa validation). totals garde en JSON les minutes prévues et pointées par employé.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('timesheet_approvals')) {
            return;
        }
        $this->schema()->create('timesheet_approvals', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->date('week_start');
            $table->integer('approved_by')->nullable();
            $table->dateTime('approved_at');
            $table->string('fingerprint', 40);
            $table->text('totals');
            $table->timestamps();

            $table->unique(['store_id', 'week_start']);
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('timesheet_approvals');
    }
};
