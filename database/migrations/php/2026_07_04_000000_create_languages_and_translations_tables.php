<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('languages')) {
            $this->schema()->create('languages', function (Blueprint $table) {
                $table->string('code', 10)->primary();
                $table->string('name', 100);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!$this->schema()->hasTable('translations')) {
            $this->schema()->create('translations', function (Blueprint $table) {
                $table->increments('id');
                $table->string('language_code', 10);
                $table->string('key', 190);
                $table->text('value');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();

                $table->foreign('language_code')->references('code')->on('languages')
                      ->onDelete('cascade')->onUpdate('cascade');
                $table->unique(['language_code', 'key']);
                $table->index('key');
            });
        }

        $now = date('Y-m-d H:i:s');
        $this->capsule->table('languages')->insert([
            ['code' => 'fr', 'name' => 'Français', 'is_active' => 1, 'is_default' => 1, 'sort_order' => 0, 'created_at' => $now],
            ['code' => 'en', 'name' => 'English',  'is_active' => 1, 'is_default' => 0, 'sort_order' => 1, 'created_at' => $now],
            ['code' => 'ja', 'name' => '日本語',    'is_active' => 1, 'is_default' => 0, 'sort_order' => 2, 'created_at' => $now],
        ]);
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('translations');
        $this->schema()->dropIfExists('languages');
    }
};
