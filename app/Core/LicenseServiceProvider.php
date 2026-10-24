<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

final class LicenseServiceProvider extends ServiceProvider
{
    public const SETTINGS_KEY = 'enabled_bundles';

    /** Défauts si ni réglage Owner ni config/license.php — à garder identique à config/license.php (vérifié par un test). */
    public const DEFAULT_FEATURES = ['daily-report', 'messaging', 'store-photos', 'timeoff', 'shift-swap', 'shift-claim', 'resignation-report', 'salary-report', 'hiring-report', 'timeclock', 'team-directory', 'notebook'];

    public function register(): void
    {
        $enabledFeatures = $this->loadEnabledFeatures();
        $this->container->instance(FeatureManager::class, new FeatureManager($enabledFeatures));
    }

    /**
     * Résout la liste des bundles activés, par ordre de priorité :
     * 1. Réglage Owner en base (`app_settings.enabled_bundles`, écrit depuis /admin/bundles) ;
     * 2. `config/license.php` (déploiements gérés / première installation) ;
     * 3. Défauts codés en dur.
     */
    private function loadEnabledFeatures(): array
    {
        $stored = $this->storedSetting();
        if ($stored !== null) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = BASE_PATH . '/config/license.php';
        if (file_exists($path)) {
            $config = require $path;
            return $config['enabled_features'] ?? [];
        }

        // Default to all core features enabled for self-hosted version if no license file.
        return self::DEFAULT_FEATURES;
    }

    /**
     * Réglage Owner en base, ou null s'il est absent — y compris quand la base n'est pas encore migrée.
     *
     * Ce fournisseur s'exécute dans le constructeur d'Application, donc AVANT toute migration : c'est le cas de
     * l'installateur web (étape C) et de `php scripts/db-migrate.php` sur une base neuve. Sans cette tolérance,
     * la lecture échoue sur « no such table: app_settings » et aucune installation neuve n'aboutit. On retombe
     * alors sur config/license.php, exactement comme quand la clé n'existe pas.
     *
     * Seules les erreurs de base sont interceptées ; pas de journalisation ici : le journal applicatif écrit
     * lui-même en base, indisponible à ce stade.
     */
    private function storedSetting(): ?string
    {
        try {
            return $this->container->make(AppSettingsRepositoryInterface::class)->get(self::SETTINGS_KEY);
        } catch (\Illuminate\Database\QueryException|\PDOException) {
            return null;
        }
    }
}
