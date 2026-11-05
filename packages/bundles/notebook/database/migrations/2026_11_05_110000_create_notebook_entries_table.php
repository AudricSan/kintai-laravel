<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notebook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            // Null = note de toute l'organisation, sinon rattachée à un magasin.
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->boolean('pinned')->default(false);
            // Dernier jour (inclus) où la note est affichée, dans le fuseau de l'instance ; null = sans expiration.
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'pinned']);
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_entries');
    }
};
