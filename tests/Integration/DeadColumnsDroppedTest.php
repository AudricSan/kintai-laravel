<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Audit de septembre 2026 (task/mermission.md) : ces colonnes n'étaient jamais lues ni
 * écrites par le code applicatif, remplacées en cours de route par d'autres colonnes/tables
 * sans que l'ancienne ait été retirée (2026_09_23_000000_drop_dead_columns.php). Une nouvelle
 * installation ne doit plus les créer.
 */
final class DeadColumnsDroppedTest extends TestCase
{
    private const DEAD_COLUMNS = [
        'users' => ['profile_image', 'email_verified_at', 'furigana'],
        'hiring_reports' => ['furigana'],
        'store_user' => ['hourly_rates', 'deduction_overrides'],
        'stores' => ['deduction_settings', 'excel_import_settings', 'break_settings', 'opening_hours'],
        'daily_reports' => ['is_finalized', 'finalized_by', 'finalized_at', 'pdf_path', 'pdf_generated_at'],
        'roles' => ['icon'],
        'shifts' => ['wage_breakdown'],
    ];

    /** Colonnes voisines qui doivent, elles, survivre — garde-fou contre un dropColumn trop large. */
    private const SURVIVING_COLUMNS = [
        'users' => ['avatar_path', 'furigana_last_name', 'furigana_first_name'],
        'hiring_reports' => ['furigana_last_name', 'furigana_first_name'],
        'store_user' => ['subject_to_deductions'],
        'stores' => ['daily_report_settings'],
        'daily_reports' => ['status', 'validated_by', 'validated_at'],
        'roles' => ['slug', 'is_system'],
        'shifts' => ['estimated_salary'],
    ];

    public function testFreshInstallDoesNotHaveTheseColumns(): void
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

        $schema = $capsule->getConnection()->getSchemaBuilder();

        foreach (self::DEAD_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(
                    $schema->hasColumn($table, $column),
                    "$table.$column devrait avoir été supprimée"
                );
            }
        }

        foreach (self::SURVIVING_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    $schema->hasColumn($table, $column),
                    "$table.$column ne devrait pas avoir été supprimée"
                );
            }
        }
    }
}
