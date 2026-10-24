<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('store_import_settings')) {
            return;
        }
        $this->schema()->create('store_import_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id')->unique();
            $table->integer('col_start')->default(4);
            $table->integer('col_end')->default(52);
            $table->integer('base_hour')->default(6);
            $table->integer('minutes_per_col')->default(30);
            $table->integer('block_size')->default(9);
            $table->integer('shift_rows')->default(7);
            $table->string('sheet_filter_pattern')->default('^\d{4}$');
            $table->integer('auto_pause_after_minutes')->default(0);
            $table->integer('auto_pause_minutes')->default(30);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('store_import_settings');
    }
};
