<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_check_settings', function (Blueprint $table) {
            $table->foreignId('store_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // « HH:MM » dans le fuseau de l'instance.
            $table->string('deadline_time', 5)->default('12:00');
            $table->string('window_start_time', 5)->default('06:00');
            // Jours ISO (1 = lundi ... 7 = dimanche) pour lesquels le planning du lendemain est à valider.
            $table->json('weekdays')->nullable();
            // Employés du magasin qui reçoivent le PDF du planning validé.
            $table->json('mail_recipient_ids')->nullable();
            $table->boolean('include_open_shifts')->default(false);
            $table->timestamps();
        });

        // Une validation par magasin et par jour visé : pending, submitted, validated, rejected ou escalated.
        Schema::create('planning_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('target_date');
            $table->string('status', 20)->default('pending');
            $table->boolean('confirmed_empty')->default(false);
            // L'historique survit à la suppression d'un compte : les références passent à nul.
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            // Envoi du PDF : vide (jamais envoyé), sent ou failed.
            $table->string('send_status', 10)->default('');
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            // Empreinte des shifts au dernier envoi : un planning modifié depuis propose un renvoi.
            $table->string('shifts_hash', 64)->default('');
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'target_date']);
            $table->index(['store_id', 'status']);
        });

        // Historique des envois : le premier envoi et chaque renvoi.
        Schema::create('planning_check_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10);
            $table->string('shifts_hash', 64)->default('');
            $table->unsignedSmallInteger('recipient_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_check_sends');
        Schema::dropIfExists('planning_checks');
        Schema::dropIfExists('planning_check_settings');
    }
};
