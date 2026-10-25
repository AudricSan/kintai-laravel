<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Le registry officiel devient celui des bundles Laravel (dépôt kintai-laravel-bundles, voir
 * config/official-bundle-registry.php) : une instance passée sous Laravel y trouve les versions 3.x de ses bundles
 * officiels, dont les vues sont en Blade. Seule l'entrée officielle qui pointe encore sur l'ancien registry change ; un
 * registry ajouté à la main n'est jamais touché. Les bundles déjà installés en gardent la trace (installed_bundles).
 */
return new class($this->capsule) extends Migration {
    private const OLD_URL = 'https://raw.githubusercontent.com/AudricSan/KintaiBundle/main/registry.json';

    public function up(): void
    {
        if (!$this->schema()->hasTable('bundle_registries')) {
            return;
        }

        // Chemin relatif au fichier plutôt que BASE_PATH, comme 2026_09_18_000001_seed_official_bundle_registry.php.
        $official = require dirname(__DIR__, 3) . '/config/official-bundle-registry.php';
        $db       = $this->capsule->getConnection();

        $db->table('bundle_registries')
            ->where('is_official', true)
            ->where('url', self::OLD_URL)
            ->update(['name' => $official['name'], 'url' => $official['url']]);

        if ($this->schema()->hasTable('installed_bundles')) {
            $db->table('installed_bundles')->where('source_registry_url', self::OLD_URL)->update(['source_registry_url' => $official['url']]);
        }
    }

    public function down(): void
    {
        if (!$this->schema()->hasTable('bundle_registries')) {
            return;
        }

        $official = require dirname(__DIR__, 3) . '/config/official-bundle-registry.php';
        $this->capsule->getConnection()->table('bundle_registries')
            ->where('is_official', true)
            ->where('url', $official['url'])
            ->update(['url' => self::OLD_URL]);
    }
};
