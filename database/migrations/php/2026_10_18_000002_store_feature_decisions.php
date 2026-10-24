<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;
use kintai\Core\Extension\KnownStoreFeatureKeys;

/**
 * store_features enregistrait seulement les fonctionnalités activées : aucune ligne = magasin jamais configuré, tout
 * actif ; sinon une clé absente était désactivée. Enregistrer un magasin pendant qu'un bundle était désactivé (ou
 * installer un bundle après) laissait donc sa fonctionnalité éteinte. Désormais chaque ligne est une décision
 * (enabled) et une fonctionnalité sans décision est active.
 *
 * Conversion des magasins déjà configurés, pour qu'ils gardent exactement leur état : chaque fonctionnalité connue
 * (Core et bundles installés, actifs ou non) absente de leur liste reçoit une décision « désactivée ».
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('store_features', 'enabled')) {
            return;
        }
        $this->schema()->table('store_features', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });

        $conn  = $this->capsule->getConnection();
        $known = KnownStoreFeatureKeys::all();
        $byStore = [];
        foreach ($conn->table('store_features')->get(['store_id', 'feature_key']) as $row) {
            $byStore[(int) $row->store_id][] = (string) $row->feature_key;
        }
        foreach ($byStore as $storeId => $enabled) {
            foreach (array_diff($known, $enabled) as $key) {
                $conn->table('store_features')->insert(['store_id' => $storeId, 'feature_key' => $key, 'enabled' => false]);
            }
        }
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('store_features', 'enabled')) {
            return;
        }
        $this->capsule->getConnection()->table('store_features')->where('enabled', false)->delete();
        $this->schema()->table('store_features', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
