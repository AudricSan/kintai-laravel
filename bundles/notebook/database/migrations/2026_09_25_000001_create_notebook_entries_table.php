<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * store_id nullable : null = note visible par toute l'organisation, sinon
 * rattachée à un store précis (voir NotebookEntry::findVisibleForStores()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notebook_entries')) {
            return;
        }
        Schema::create('notebook_entries', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('author_id');
            $table->integer('store_id')->nullable();
            $table->text('content');
            $table->boolean('pinned')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['store_id', 'pinned']);
            $table->index('expires_at');
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_entries');
    }
};
