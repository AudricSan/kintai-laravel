<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Permet à un store en JPY de choisir l'affichage du yen : kanji (円, historique)
 * ou symbole international (¥).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('stores', 'currency_symbol_style')) {
            return;
        }
        $this->schema()->table('stores', function (Blueprint $table) {
            $table->string('currency_symbol_style')->default('kanji')->after('currency');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('stores', 'currency_symbol_style')) {
            return;
        }
        $this->schema()->table('stores', function (Blueprint $table) {
            $table->dropColumn('currency_symbol_style');
        });
    }
};
