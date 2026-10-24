<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('salary_reports')) {
            return;
        }
        $this->schema()->create('salary_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('target_month', 7);
            $table->string('store_name')->nullable();
            $table->string('person_in_charge')->nullable();
            $table->decimal('total_payment', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('income_tax_base', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('residence_tax', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_payment', 12, 2)->default(0);
            $table->integer('active_employees')->default(0);
            $table->decimal('hand_delivered_salary', 12, 2)->default(0);
            $table->decimal('staff_man_hours', 10, 2)->default(0);
            $table->decimal('staff_total_payment', 12, 2)->default(0);
            $table->decimal('staff_avg_hourly_wage', 10, 2)->default(0);
            $table->text('employee_work_hours')->nullable();
            $table->integer('new_hires')->default(0);
            $table->integer('resigned_staff')->default(0);
            $table->text('hire_registrations')->nullable();
            $table->text('remarks')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['store_id', 'target_month']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('salary_reports');
    }
};
