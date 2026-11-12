<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retenues personnalisées d'un magasin, en plus des retenues fixes de `salary_report_settings`.
        Schema::create('salary_report_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // `percent` : pourcentage du brut de l'employé ; `fixed` : montant mensuel fixe par employé payé.
            $table->string('kind', 10);
            $table->decimal('value', 10, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });

        // Instantané des retenues personnalisées au calcul : [{name, kind, value, amount}]. Les rapports gardent ce qui a été
        // appliqué, même si la retenue est ensuite modifiée ou supprimée.
        Schema::table('salary_reports', function (Blueprint $table) {
            $table->json('deduction_lines')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salary_reports', function (Blueprint $table) {
            $table->dropColumn('deduction_lines');
        });
        Schema::dropIfExists('salary_report_deductions');
    }
};
