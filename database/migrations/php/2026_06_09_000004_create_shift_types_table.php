<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shift_types')) {
            return;
        }
        $this->schema()->create('shift_types', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('name');
            $table->string('code');
            $table->string('start_time');
            $table->string('end_time');
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->string('color')->default('#3B82F6');
            $table->integer('sort_order')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'is_active', 'sort_order']);
            
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shift_types');
    }
};
