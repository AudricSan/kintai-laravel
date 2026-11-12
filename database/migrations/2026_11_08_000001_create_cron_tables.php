<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jetons de l'appel HTTP des tâches planifiées (`/cron/run`). Seule l'empreinte du jeton est stockée : le jeton
        // en clair n'est montré qu'une fois, à la création.
        Schema::create('cron_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Dernière exécution de chaque tâche du planificateur (une ligne par tâche), pour l'écran « Tâches planifiées ».
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->string('name', 150)->primary();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('last_runtime_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('cron_tokens');
    }
};
