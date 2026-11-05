<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_directory_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->string('skills', 500)->nullable();
            $table->string('languages_spoken')->nullable();
            $table->string('hobbies', 500)->nullable();
            // Présence dans l'annuaire : oui par défaut ; sans ligne, l'employé est visible.
            $table->boolean('show_in_directory')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_directory_profiles');
    }
};
