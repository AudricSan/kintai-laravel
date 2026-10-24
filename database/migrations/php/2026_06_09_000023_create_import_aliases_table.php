<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('import_aliases')) {
            return;
        }
        $this->schema()->create('import_aliases', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('alias_name');
            $table->integer('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'alias_name']);
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('import_aliases');
    }
};
