<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('store_user')) {
            return;
        }
        $this->schema()->create('store_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('user_id');
            $table->string('role')->default('staff');
            $table->string('staff_code')->nullable();
            $table->string('hire_date')->nullable();
            $table->string('contract_type')->nullable();
            $table->integer('is_manager')->default(0);
            $table->text('hourly_rates')->nullable();
            $table->integer('is_active')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->text('deduction_overrides')->nullable();
            $table->integer('subject_to_deductions')->default(0);

            $table->unique(['store_id', 'user_id']);
            $table->unique(['store_id', 'staff_code']);
            $table->index('user_id');
            $table->index(['store_id', 'role']);
            
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('store_user');
    }
};
