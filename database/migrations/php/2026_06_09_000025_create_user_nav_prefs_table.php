<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('user_nav_prefs')) {
            return;
        }
        $this->schema()->create('user_nav_prefs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->text('hidden_items')->default($this->textDefault('[]'));
            $table->text('section_order')->nullable();
            $table->text('bottom_nav_items')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_nav_prefs');
    }
};
