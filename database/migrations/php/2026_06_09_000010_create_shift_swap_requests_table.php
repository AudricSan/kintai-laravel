<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shift_swap_requests')) {
            return;
        }
        $this->schema()->create('shift_swap_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('requester_id');
            $table->integer('target_user_id')->nullable();
            $table->integer('requester_shift_id');
            $table->integer('target_shift_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->integer('accepted_by_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->integer('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['store_id', 'status']);
            $table->index('requester_id');
            $table->index('target_user_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('requester_shift_id')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('target_shift_id')->references('id')->on('shifts')->onDelete('set null');
            $table->foreign('requester_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('accepted_by_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shift_swap_requests');
    }
};
