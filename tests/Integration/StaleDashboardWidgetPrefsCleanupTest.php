<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\Migration;
use kintai\Core\Database\MigrationRunner;
use kintai\Domain\Eloquent\UserDashboardPref;
use PHPUnit\Framework\TestCase;

/**
 * Régression : plusieurs refontes du dashboard ont renommé les clés de widgets
 * (dashboard employé : "timeclocks"/"timeoff"/"open_shifts" → "timeclock"/
 * "monthly_stats"/...) sans jamais migrer les lignes user_dashboard_prefs
 * existantes. Comme getEnabledWidgets() applique une liste blanche stricte
 * (array_intersect avec le catalogue courant), toute ligne sauvegardée sous
 * l'ancien schéma masque silencieusement les widgets actuels — dont le widget
 * salaire estimé "monthly_stats", pourtant central pour un employé. La migration
 * 2026_08_05_000000_reset_stale_dashboard_widget_prefs supprime ces lignes
 * corrompues pour que les contrôleurs retombent sur le jeu de widgets par défaut.
 */
final class StaleDashboardWidgetPrefsCleanupTest extends TestCase
{
    private function migratedCapsule(): Capsule
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        $runner->run();

        return $capsule;
    }

    private function reRunCleanupMigration(Capsule $capsule): void
    {
        $file = dirname(__DIR__, 2) . '/database/migrations/php/2026_08_05_000000_reset_stale_dashboard_widget_prefs.php';
        $loader = new class($capsule) {
            public Capsule $capsule;
            public function __construct(Capsule $capsule) { $this->capsule = $capsule; }
            public function load(string $file): Migration
            {
                return require $file;
            }
        };
        $loader->load($file)->up();
    }

    public function testRemovesRowsWithKeysFromRetiredWidgetSchema(): void
    {
        $capsule = $this->migratedCapsule();

        // Ancien schéma employé (pré-refonte), entièrement inconnu du catalogue actuel.
        UserDashboardPref::create([
            'user_id' => 101, 'dashboard_type' => 'employee',
            'widgets' => json_encode(['timeclocks', 'timeoff', 'swaps', 'open_shifts', 'messages']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Schéma employé partiellement migré : mélange de clés obsolètes (kpi_counters,
        // quick_nav, timeclocks_today viennent du catalogue admin) et actuelles.
        UserDashboardPref::create([
            'user_id' => 102, 'dashboard_type' => 'employee',
            'widgets' => json_encode(['kpi_counters', 'quick_nav', 'shifts_today', 'timeclocks_today']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Ligne saine, à jour avec le catalogue actuel : ne doit pas être touchée.
        UserDashboardPref::create([
            'user_id' => 103, 'dashboard_type' => 'employee',
            'widgets' => json_encode(['timeclock', 'shifts_today', 'upcoming', 'monthly_stats', 'pending_timeoff', 'pending_swaps']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Ligne admin saine, ne doit pas être touchée par le nettoyage employé.
        UserDashboardPref::create([
            'user_id' => 104, 'dashboard_type' => 'admin',
            'widgets' => json_encode(['kpi_counters', 'quick_nav', 'shifts_today']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->reRunCleanupMigration($capsule);

        $remainingUserIds = UserDashboardPref::query()->pluck('user_id')->all();
        sort($remainingUserIds);

        $this->assertSame([103, 104], $remainingUserIds);
    }

    public function testIsIdempotentAndSafeOnAlreadyCleanData(): void
    {
        $capsule = $this->migratedCapsule();

        UserDashboardPref::create([
            'user_id' => 201, 'dashboard_type' => 'employee',
            'widgets' => json_encode(['timeclock', 'monthly_stats']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->reRunCleanupMigration($capsule);
        $this->reRunCleanupMigration($capsule);

        $this->assertSame(1, UserDashboardPref::query()->count());
    }
}
