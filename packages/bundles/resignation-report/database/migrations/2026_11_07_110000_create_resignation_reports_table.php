<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resignation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // L'employé du rapport ; le lien se défait si le compte est purgé (à l'anonymisation, le rapport est supprimé).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('employee_number')->nullable();
            $table->string('employee_name')->nullable();
            $table->date('resignation_date')->nullable();
            $table->text('reason')->nullable();
            $table->text('resignation_notice')->nullable();
            $table->text('notes')->nullable();
            $table->string('person_in_charge')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'resignation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resignation_reports');
    }
};
