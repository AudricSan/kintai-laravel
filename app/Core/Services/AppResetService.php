<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Database\SqliteForeignKeys;

/**
 * Réinitialisation de l'application depuis /admin/backup (bouton "Danger zone").
 * Toujours précédée d'une sauvegarde complète (BackupService::create()) — voir
 * AppResetController, qui orchestre backup + confirmation + appel à ce service.
 */
final class AppResetService
{
    /** Jamais vidées en mode "données" : identité de l'installation + configuration essentielle. */
    private const ALWAYS_PRESERVED = [
        'migrations', 'users', 'roles', 'role_permissions', 'role_assignments', 'app_settings',
    ];

    /** Catégories optionnelles du mode "données" : cochées = conservées, décochées (défaut) = vidées. */
    public const OPTIONAL_CATEGORIES = [
        'stores'      => ['stores', 'store_user', 'store_features', 'store_deduction_settings', 'store_import_settings'],
        'shift_types' => ['shift_types', 'shift_type_stores', 'user_shift_type_rates'],
        'ui_prefs'    => ['user_dashboard_prefs', 'user_dashboard_layouts', 'dashboard_role_layouts', 'user_nav_prefs'],
    ];

    public function __construct(
        private readonly Capsule $capsule,
        private readonly MigrationRunner $migrationRunner,
    ) {
    }

    /**
     * Vide toutes les données opérationnelles en conservant l'Owner, les stores/rôles
     * (sauf catégories explicitement décochées) et la configuration de l'installation.
     *
     * @param string[] $keepCategories Clés de OPTIONAL_CATEGORIES à préserver en plus.
     * @return string[] Tables vidées.
     */
    public function resetData(array $keepCategories): array
    {
        $preserved = self::ALWAYS_PRESERVED;
        foreach ($keepCategories as $category) {
            if (isset(self::OPTIONAL_CATEGORIES[$category])) {
                $preserved = [...$preserved, ...self::OPTIONAL_CATEGORIES[$category]];
            }
        }

        $toWipe = array_values(array_diff($this->allTableNames(), $preserved));
        $this->truncateTables($toWipe);

        return $toWipe;
    }

    /**
     * Vide toute la base (y compris users/roles/app_settings) et supprime le verrou
     * d'installation : la prochaine requête sera redirigée vers l'installeur web.
     * Les fichiers uploadés (déjà présents dans la sauvegarde préalable) sont aussi purgés.
     *
     * @return string[] Tables vidées.
     */
    public function resetFactory(): array
    {
        // Retrait du tracking *avant* le vidage des tables : si le process s'arrête
        // entre les deux, on obtient au pire "migration à rejouer, données encore
        // présentes" — inoffensif (la garde d'idempotence de la migration la rend
        // no-op, ou executeMigration() absorbe l'éventuelle contrainte unique déjà
        // en place) — plutôt que "données vidées, migration jamais rejouée", qui
        // reproduirait le bug que ce mécanisme corrige.
        $this->capsule->table('migrations')
            ->whereIn('migration', $this->migrationRunner->getSeedMigrationNames())
            ->delete();

        $toWipe = array_values(array_diff($this->allTableNames(), ['migrations']));
        $this->truncateTables($toWipe);

        $this->removeInstalledLock();
        $this->clearUploads();

        return $toWipe;
    }

    private function allTableNames(): array
    {
        $conn = $this->capsule->getConnection();
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $conn->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            return array_map(fn($r) => $r->name, $rows);
        }

        $rows = $conn->select('SHOW TABLES');
        $key = 'Tables_in_' . $conn->getConfig('database');
        return array_map(function ($row) use ($key) {
            $arr = (array) $row;
            return $arr[$key] ?? reset($arr);
        }, $rows);
    }

    private function truncateTables(array $tables): void
    {
        $conn = $this->capsule->getConnection();
        $isMysql = $conn->getDriverName() === 'mysql';

        if ($isMysql) {
            $conn->statement('SET FOREIGN_KEY_CHECKS = 0');
        }

        // Sous SQLite aussi les clés étrangères sont actives : vidage dans un ordre quelconque sans elles.
        SqliteForeignKeys::without($conn, function () use ($tables): void {
            foreach ($tables as $table) {
                $this->capsule->table($table)->truncate();
            }
        });

        if ($isMysql) {
            $conn->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function removeInstalledLock(): void
    {
        $lockFile = storage_path('installed.lock');
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    private function clearUploads(): void
    {
        $uploadsDir = storage_path('uploads');
        if (!is_dir($uploadsDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }
}
