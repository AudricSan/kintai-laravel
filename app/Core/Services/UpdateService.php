<?php

declare(strict_types=1);

namespace kintai\Core\Services;

final class UpdateService
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? BASE_PATH;
    }

    /**
     * Lit la version installée directement depuis config/app.php — seule
     * source de vérité, sans indirection ni fichier annexe. Le champ 'version'
     * y est un littéral simple (plus de env('APP_VERSION', ...)) que
     * GithubUpdateService::applyUpdate() réécrit lui-même avec le tag exact
     * (vrai Z inclus) à chaque mise à jour appliquée — voir setCurrentVersion().
     */
    public function getCurrentVersion(): string
    {
        $configFile = $this->basePath . '/config/app.php';
        if (!file_exists($configFile)) {
            return '0.0.0';
        }
        $config = require $configFile;

        return $config['version'] ?? '0.0.0';
    }

    /** Réécrit le champ 'version' de config/app.php avec le tag exact appliqué par la mise à jour. */
    public function setCurrentVersion(string $version): void
    {
        $configFile = $this->basePath . '/config/app.php';
        $contents = (string) file_get_contents($configFile);
        $updated = preg_replace(
            "/'version'\s*=>\s*'[^']*'/",
            "'version' => '" . addslashes($version) . "'",
            $contents,
            1
        );
        file_put_contents($configFile, $updated);
    }

    public function getPendingMigrations(): array
    {
        $migrated = $this->getExecutedMigrations();
        $all = $this->getAvailableMigrations();
        return array_values(array_diff($all, $migrated));
    }

    private function getExecutedMigrations(): array
    {
        try {
            return \Illuminate\Database\Capsule\Manager::table('migrations')
                ->pluck('migration')
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getAvailableMigrations(): array
    {
        $path = BASE_PATH . '/database/migrations/php';
        if (!is_dir($path)) {
            return [];
        }
        $files = glob($path . '/*.php');
        if ($files === false) {
            return [];
        }
        sort($files);
        return array_map(function (string $f): string {
            return basename($f, '.php');
        }, $files);
    }
}
