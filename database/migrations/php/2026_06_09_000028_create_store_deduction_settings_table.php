<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('store_deduction_settings')) {
            return;
        }
        $this->schema()->create('store_deduction_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id')->unique();
            $table->integer('enabled')->default(0);
            $table->decimal('health_insurance_rate', 6, 2)->default(4.99);
            $table->decimal('pension_rate', 6, 2)->default(9.15);
            $table->decimal('employment_insurance_rate', 6, 2)->default(0.60);
            $table->decimal('income_tax_rate', 6, 2)->default(0);
            $table->decimal('resident_tax_monthly', 10, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('store_deduction_settings');
    }
};
