<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Le rapport appartient au magasin : il survit à la suppression de son auteur.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('report_date');
            $table->decimal('sales_total', 12, 2)->default(0);
            $table->unsignedInteger('customer_count')->default(0);
            $table->decimal('labor_cost', 12, 2)->default(0);
            $table->decimal('waste_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            // draft, submitted, validated.
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            // Nul pour une validation automatique.
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'report_date']);
            $table->index(['store_id', 'status', 'report_date']);
            $table->index(['author_id', 'report_date']);
        });

        Schema::create('daily_report_settings', function (Blueprint $table) {
            $table->foreignId('store_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('auto_send_on_validate')->default(false);
            // « HH:MM » : à cette heure, le rapport de la veille est validé s'il ne l'est pas encore. Nul : désactivée.
            $table->string('auto_validate_time', 5)->nullable();
            $table->string('reminder_time', 5)->default('15:00');
            $table->string('no_report_time', 5)->default('18:00');
            // Colonnes de l'aperçu des shifts du lendemain.
            $table->json('show_columns')->nullable();
            // per_day, cumulative_system (cumul calculé à l'affichage), cumulative_input (valeurs saisies déjà cumulées).
            $table->string('cumulative_mode')->default('per_day');
            // Employés du magasin qui reçoivent le rapport validé.
            $table->json('mail_recipient_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_settings');
        Schema::dropIfExists('daily_reports');
    }
};
