<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->string('color', 7)->default('#3B82F6');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shift_type_store', function (Blueprint $table) {
            $table->foreignId('shift_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->primary(['shift_type_id', 'store_id']);
        });

        Schema::create('shift_type_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('hourly_rate', 10, 2);
            $table->timestamps();

            $table->unique(['user_id', 'shift_type_id']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('shift_date');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('pause_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->decimal('hourly_rate_override', 10, 2)->nullable();
            $table->unsignedInteger('net_minutes_override')->nullable();
            $table->string('ical_uid')->unique();
            $table->unsignedInteger('ical_sequence')->default(0);
            $table->foreignId('replaced_by_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'shift_date']);
            $table->index(['user_id', 'shift_date']);
            $table->index(['user_id', 'starts_at', 'ends_at']);
        });

        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availabilities');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_type_user');
        Schema::dropIfExists('shift_type_store');
        Schema::dropIfExists('shift_types');
    }
};
