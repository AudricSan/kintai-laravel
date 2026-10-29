<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supprimer une conversation la masque pour soi seul (audit n°8 de Kintai, décision D5) : hidden_at note quand un
 * participant l'a retirée de sa liste. Elle n'est effacée de la base que lorsque tous ses participants l'ont masquée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('thread_participants') || Schema::hasColumn('thread_participants', 'hidden_at')) {
            return;
        }
        Schema::table('thread_participants', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        // Pas de suppression : la colonne porte l'état des listes de conversations.
    }
};
