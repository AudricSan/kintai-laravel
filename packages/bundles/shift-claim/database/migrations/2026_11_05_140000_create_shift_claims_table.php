<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Une candidature par employé et par shift : se représenter après un retrait ou un refus la remet en attente.
            $table->unique(['shift_id', 'user_id']);
            $table->index(['store_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('shift_claim_offers', function (Blueprint $table) {
            $table->id();
            // Un shift attribué proposé aux candidatures (son titulaire le garde jusqu'à l'approbation d'un candidat).
            $table->foreignId('shift_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_claim_offers');
        Schema::dropIfExists('shift_claims');
    }
};
