<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal d'audit métier : une ligne par action. L'acteur et le magasin passent à null si leur ligne disparaît ;
        // la cible (type + identifiant) est une référence souple, la cible a pu être supprimée.
        Schema::create('activity_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index(['actor_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_entries');
    }
};
