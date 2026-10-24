<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Le bundle « feedback » n'existe plus : la migration supprime sa table, ses permissions et son slug dans
 * les réglages, sans toucher aux autres bundles.
 */
final class RemoveFeedbackBundleTest extends TestCase
{
    private const MIGRATION = '2026_10_14_000000_remove_feedback_bundle.php';

    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $this->capsule, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            $p = new \ReflectionProperty($runner, $prop);
            $p->setAccessible(true);
            $p->setValue($runner, $value);
        }
        $runner->run();
    }

    private function runMigrationAgain(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/php/' . self::MIGRATION;
        $migration->up();
    }

    public function testAFreshInstallHasNoFeedbackTable(): void
    {
        $this->assertFalse($this->capsule->getConnection()->getSchemaBuilder()->hasTable('employee_feedbacks'));
    }

    public function testTheMigrationRemovesFeedbackTracesAndKeepsTheRest(): void
    {
        $db  = $this->capsule->getConnection();
        $now = date('Y-m-d H:i:s');

        // Instance mise à jour : la table, les permissions et les réglages du bundle existent encore.
        $db->getSchemaBuilder()->create('employee_feedbacks', function ($t) {
            $t->increments('id');
        });
        $roleId = (int) $db->table('roles')->insertGetId(['name' => 'Custom', 'slug' => 'custom', 'is_system' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('role_permissions')->insert([
            ['role_id' => $roleId, 'permission_key' => 'feedbacks.view'],
            ['role_id' => $roleId, 'permission_key' => 'timeoff.view'],
        ]);
        $db->table('app_settings')->insert(['key' => 'enabled_bundles', 'value' => json_encode(['timeoff', 'feedback', 'messaging'])]);
        $db->table('installed_bundles')->insert([
            ['slug' => 'feedback', 'active_version' => '1.1.0', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'timeoff', 'active_version' => '1.1.0', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->runMigrationAgain();

        $this->assertFalse($db->getSchemaBuilder()->hasTable('employee_feedbacks'));
        $this->assertSame(['timeoff.view'], $db->table('role_permissions')->where('role_id', $roleId)->pluck('permission_key')->all());
        $this->assertSame(['timeoff', 'messaging'], json_decode((string) $db->table('app_settings')->where('key', 'enabled_bundles')->value('value'), true));
        $this->assertSame(['timeoff'], $db->table('installed_bundles')->pluck('slug')->all());
    }
}
