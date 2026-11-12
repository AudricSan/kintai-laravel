<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Rapport d'un seul employé ; nul = rapport global du magasin. Il suit l'employé s'il est purgé (jamais « global »).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // « AAAA-MM ».
            $table->string('target_month', 7);
            $table->foreignId('person_in_charge_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_payment', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('income_tax_base', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('residence_tax', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_payment', 12, 2)->default(0);
            $table->unsignedInteger('active_employees')->default(0);
            $table->decimal('hand_delivered_salary', 12, 2)->default(0);
            $table->decimal('bank_transfer_salary', 12, 2)->default(0);
            $table->decimal('staff_man_hours', 10, 2)->default(0);
            $table->decimal('staff_total_payment', 12, 2)->default(0);
            $table->decimal('staff_avg_hourly_wage', 10, 2)->default(0);
            // Minutes par employé au moment du calcul : [{user_id, minutes}]. Les noms sont relus à l'affichage.
            $table->json('employee_hours')->nullable();
            $table->unsignedInteger('new_hires')->default(0);
            $table->unsignedInteger('resigned_staff')->default(0);
            $table->text('hire_registrations')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un nul n'étant jamais égal à un autre, l'unicité du rapport global par mois est vérifiée par le code.
            $table->unique(['store_id', 'target_month', 'user_id']);
            $table->index(['store_id', 'target_month']);
        });

        // Retenues d'un magasin (une ligne par magasin, créée à la première sauvegarde) : taux en pourcentage du brut.
        Schema::create('salary_report_settings', function (Blueprint $table) {
            $table->foreignId('store_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('deductions_enabled')->default(false);
            $table->decimal('health_insurance_rate', 6, 2)->default(0);
            $table->decimal('pension_rate', 6, 2)->default(0);
            $table->decimal('employment_insurance_rate', 6, 2)->default(0);
            $table->decimal('income_tax_rate', 6, 2)->default(0);
            // Montant fixe mensuel.
            $table->decimal('resident_tax_monthly', 10, 2)->default(0);
            $table->timestamps();
        });

        // Employés d'un magasin soumis aux retenues (absence de ligne = non soumis).
        Schema::create('salary_report_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('subject_to_deductions')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_report_members');
        Schema::dropIfExists('salary_report_settings');
        Schema::dropIfExists('salary_reports');
    }
};
