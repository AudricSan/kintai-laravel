<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Recrée remember_tokens (droppée le 2026-08-06 faute d'implémentation, voir
 * 2026_08_06_000000_drop_unused_remember_tokens_and_sessions_tables.php), cette fois avec
 * une véritable implémentation ("rester connecté" 30 jours, voir AuthService).
 *
 * Schéma en sélecteur/validateur (comme les autres tokens du projet, ex. api_tokens) plutôt
 * qu'un seul token stocké en clair : le cookie contient "selector.validator", seul le
 * sélecteur sert à la recherche en base (indexé), et seul le hash SHA-256 du validateur est
 * stocké — un accès en lecture à la table ne suffit donc pas à usurper une session.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('remember_tokens')) {
            return;
        }
        $this->schema()->create('remember_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('selector', 24)->unique();
            $table->string('validator_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('remember_tokens');
    }
};
