<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * La migration 2026_10_17_000000 convertit les anciennes clés employé des préférences de navigation (my_planning,
 * timeclock, my_timeoff), que la barre du haut ne lisait pas, en clés uniques (shifts, timeclocks, timeoff).
 */
final class UnifyEmployeeNavPrefKeysTest extends TestCase
{
    private const MIGRATION = '/database/migrations/php/2026_10_17_000000_unify_employee_nav_pref_keys.php';

    public function testLegacyKeysAreConvertedAndOthersKept(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $capsule, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            (new \ReflectionProperty($runner, $prop))->setValue($runner, $value);
        }
        $runner->run();

        $db = $capsule->getConnection();
        $db->table('users')->insert([
            ['id' => 1, 'email' => 'a@kintai.test', 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'password_hash' => 'x'],
            ['id' => 2, 'email' => 'b@kintai.test', 'first_name' => 'C', 'last_name' => 'D', 'display_name' => 'C D', 'password_hash' => 'x'],
        ]);
        $db->table('user_nav_prefs')->insert([
            ['user_id' => 1, 'hidden_items' => json_encode(['my_timeoff', 'messages', 'timeclock'])],
            ['user_id' => 2, 'hidden_items' => json_encode(['swaps'])],
        ]);

        $loader = new class($capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . self::MIGRATION)->up();

        $hidden = $db->table('user_nav_prefs')->orderBy('user_id')->pluck('hidden_items', 'user_id')->map(fn($j) => json_decode($j, true))->all();
        $this->assertSame([1 => ['timeoff', 'messages', 'timeclocks'], 2 => ['swaps']], $hidden);
    }

    public function testMobileKeysAreConverted(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $capsule, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            (new \ReflectionProperty($runner, $prop))->setValue($runner, $value);
        }
        $runner->run();

        $db = $capsule->getConnection();
        $db->table('users')->insert(['id' => 1, 'email' => 'a@kintai.test', 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'password_hash' => 'x']);
        $db->table('user_nav_prefs')->insert(['user_id' => 1, 'hidden_items' => '[]', 'bottom_nav_items' => json_encode(['team', 'requests', 'messages'])]);

        $loader = new class($capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . '/database/migrations/php/2026_10_17_000001_unify_mobile_nav_keys.php')->up();

        $this->assertSame(['users', 'timeoff', 'messages'], json_decode((string) $db->table('user_nav_prefs')->value('bottom_nav_items'), true));
    }
}
