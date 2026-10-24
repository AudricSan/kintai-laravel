<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * La réinitialisation du mot de passe cherche un jeton par sa valeur hachée (DatabasePasswordResetRepository::
 * findByToken()), mais la table n'était indexée que par e-mail : chaque recherche parcourait toute la table.
 * Index simple (pas unique) : une collision de hachage est impossible en pratique, mais une contrainte unique
 * ferait échouer la migration sur une base qui contiendrait déjà des doublons pour une raison quelconque.
 */
return new class($this->capsule) extends Migration {
    private const INDEX = 'password_reset_tokens_token_index';

    public function up(): void
    {
        if (!$this->schema()->hasTable('password_reset_tokens') || $this->hasIndex()) {
            return;
        }
        $this->schema()->table('password_reset_tokens', function (Blueprint $table) {
            $table->index('token', self::INDEX);
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasTable('password_reset_tokens') || !$this->hasIndex()) {
            return;
        }
        $this->schema()->table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function hasIndex(): bool
    {
        foreach ($this->schema()->getIndexes('password_reset_tokens') as $index) {
            if ($index['columns'] === ['token']) {
                return true;
            }
        }
        return false;
    }
};
