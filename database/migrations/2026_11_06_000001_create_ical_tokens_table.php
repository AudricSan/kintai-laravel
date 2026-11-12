<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un lien de flux iCal par employé et par magasin. Seule l'empreinte du jeton est stockée : le jeton
        // en clair n'est montré qu'à la création ou à la régénération.
        Schema::create('ical_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamps();

            $table->unique(['user_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ical_tokens');
    }
};
