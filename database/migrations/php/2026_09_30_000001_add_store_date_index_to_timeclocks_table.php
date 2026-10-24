<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Le widget RH du tableau de bord lit les pointages d'un store sur une période (TimeclockRepositoryInterface::
 * findByStoreBetween()), mais la table n'était indexée que par (user_id, store_id) : chaque lecture parcourait tout
 * l'historique des pointages du store.
 */
return new class($this->capsule) extends Migration {
    private const INDEX = 'timeclocks_store_id_shift_date_index';

    public function up(): void
    {
        if (!$this->schema()->hasTable('timeclocks') || $this->hasIndex()) {
            return;
        }
        $this->schema()->table('timeclocks', function (Blueprint $table) {
            $table->index(['store_id', 'shift_date'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasTable('timeclocks') || !$this->hasIndex()) {
            return;
        }
        $this->schema()->table('timeclocks', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function hasIndex(): bool
    {
        foreach ($this->schema()->getIndexes('timeclocks') as $index) {
            if (array_slice($index['columns'], 0, 2) === ['store_id', 'shift_date']) {
                return true;
            }
        }
        return false;
    }
};