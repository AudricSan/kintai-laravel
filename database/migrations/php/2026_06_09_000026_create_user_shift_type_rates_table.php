<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('user_shift_type_rates')) {
            return;
        }
        $this->schema()->create('user_shift_type_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('shift_type_id');
            $table->decimal('hourly_rate', 10, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'shift_type_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('shift_type_id')->references('id')->on('shift_types')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_shift_type_rates');
    }
};
