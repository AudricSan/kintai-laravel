<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('EUR');
            $table->string('currency_symbol_style')->default('kanji');
            $table->string('locale', 10)->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_postal')->nullable();
            $table->string('address_country')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('week_start_day')->default(1);
            $table->unsignedTinyInteger('low_staff_hour_start')->nullable();
            $table->unsignedTinyInteger('low_staff_hour_end')->nullable();
            $table->unsignedSmallInteger('min_staff_per_day')->default(0);
            $table->unsignedSmallInteger('min_shift_minutes')->default(120);
            $table->unsignedSmallInteger('max_shift_minutes')->default(480);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('staff_code')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('contract_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->unique(['store_id', 'staff_code']);
        });

        Schema::create('store_features', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('feature');
            $table->boolean('enabled')->default(true);

            $table->primary(['store_id', 'feature']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('store_features');
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
