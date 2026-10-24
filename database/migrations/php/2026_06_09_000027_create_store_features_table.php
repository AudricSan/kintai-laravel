<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('store_features')) {
            return;
        }
        $this->schema()->create('store_features', function (Blueprint $table) {
            $table->integer('store_id');
            $table->string('feature_key');
            // Décision du magasin pour cette fonctionnalité ; sans ligne, elle est active (voir StoreRepositoryInterface).
            $table->boolean('enabled')->default(true);

            $table->primary(['store_id', 'feature_key']);
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('store_features');
    }
};
