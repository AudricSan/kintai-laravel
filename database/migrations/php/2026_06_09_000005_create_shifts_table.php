<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shifts')) {
            return;
        }
        $this->schema()->create('shifts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('shift_date');
            $table->string('start_time');
            $table->string('end_time');
            $table->integer('shift_type_id')->nullable();
            $table->integer('cross_midnight')->default(0);
            $table->integer('pause_minutes')->default(0);
            $table->integer('duration_minutes')->default(0);
            $table->string('starts_at');
            $table->string('ends_at');
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('is_open')->default(0);
            $table->text('open_shift_note')->nullable();
            $table->decimal('estimated_salary', 10, 2)->nullable();
            $table->text('wage_breakdown')->nullable();
            $table->integer('ical_sequence')->default(0);

            $table->index(['store_id', 'shift_date', 'deleted_at']);
            $table->index(['user_id', 'shift_date', 'deleted_at']);
            $table->index(['user_id', 'starts_at', 'ends_at', 'deleted_at']);
            $table->index(['store_id', 'user_id', 'shift_date']);
            $table->index(['is_open', 'deleted_at']);

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('shift_type_id')->references('id')->on('shift_types')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shifts');
    }
};
