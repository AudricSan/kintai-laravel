<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_photo_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            // Rétention dépassée : les Owners ont été prévenus que les photos seront supprimées.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });

        Schema::create('store_photo_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('store_photo_submissions')->cascadeOnDelete();
            // Nom d'origine du fichier (affichage seulement, jamais utilisé comme chemin).
            $table->string('filename');
            // Nom généré du fichier dans le dossier privé du bundle (`<32 hexadécimaux>.jpg|png`).
            $table->string('path', 64)->unique();
            $table->string('mime_type', 50);
            $table->unsignedInteger('filesize');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_photo_images');
        Schema::dropIfExists('store_photo_submissions');
    }
};
