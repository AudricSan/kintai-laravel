<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('logs')) {
            return;
        }
        $this->schema()->create('logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('level', 20)->index();
            $table->string('channel', 50)->index();
            $table->text('message');
            $table->text('context')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('store_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['level', 'created_at']);
            $table->index(['channel', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('logs');
    }
};
