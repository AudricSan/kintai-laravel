<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * La colonne stores.features (JSON) faisait doublon avec la table store_features
 * (normalisée, seule source déjà utilisée par le formulaire d'édition du store)
 * et se désynchronisait silencieusement : les fonctionnalités activées par un
 * admin n'étaient alors jamais réellement appliquées pour les employés/managers.
 * store_features devient la seule source de vérité.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasColumn('stores', 'features')) {
            return;
        }
        $this->schema()->table('stores', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }

    public function down(): void
    {
        if ($this->schema()->hasColumn('stores', 'features')) {
            return;
        }
        $this->schema()->table('stores', function (Blueprint $table) {
            $table->text('features')->nullable();
        });
    }
};
