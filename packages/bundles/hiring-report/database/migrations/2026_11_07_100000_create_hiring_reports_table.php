<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hiring_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // L'employé du rapport ; le lien se défait si le compte est purgé (à l'anonymisation, le rapport est supprimé).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('employee_number')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('furigana_last_name')->nullable();
            $table->string('furigana_first_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('tax_classification')->nullable();
            $table->date('birth_date')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('education')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_phone')->nullable();
            $table->string('store_name')->nullable();
            $table->string('hired_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'hire_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_reports');
    }
};
