<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shift_claims')) {
            return;
        }
        $this->schema()->create('shift_claims', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('shift_id');
            $table->integer('user_id');
            $table->integer('store_id');
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('claimed_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('resolved_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['shift_id', 'user_id']);
            $table->foreign('shift_id')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shift_claims');
    }
};
