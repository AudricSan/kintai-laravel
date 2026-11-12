<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seuils d'heures supplémentaires déjà signalés : une ligne par employé, seuil (`daily` / `weekly`) et période
        // (jour, ou premier jour de la semaine), pour ne notifier qu'une fois.
        Schema::create('timeclock_overtime_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->date('period_start');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'kind', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeclock_overtime_alerts');
    }
};
