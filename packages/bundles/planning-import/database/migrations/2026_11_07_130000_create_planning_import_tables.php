<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grille du fichier Excel d'un magasin (une ligne par magasin, créée à la première sauvegarde ; sans ligne, les
        // valeurs par défaut du planning japonais standard s'appliquent).
        Schema::create('store_import_settings', function (Blueprint $table) {
            $table->foreignId('store_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('col_start')->default(4);
            $table->unsignedSmallInteger('col_end')->default(52);
            $table->unsignedSmallInteger('base_hour')->default(6);
            $table->unsignedSmallInteger('minutes_per_col')->default(30);
            $table->unsignedSmallInteger('block_size')->default(9);
            $table->unsignedSmallInteger('shift_rows')->default(7);
            $table->string('sheet_filter_pattern')->default('^\d{4}$');
            // 0 = pas de pause automatique ; sinon pause des shifts d'au moins cette durée.
            $table->unsignedSmallInteger('auto_pause_after_minutes')->default(0);
            $table->unsignedSmallInteger('auto_pause_minutes')->default(30);
            $table->timestamps();
        });

        // Nom lu dans un fichier (en minuscules) => employé du magasin, retenu à chaque import confirmé.
        Schema::create('import_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('staff_name');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'staff_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_aliases');
        Schema::dropIfExists('store_import_settings');
    }
};
