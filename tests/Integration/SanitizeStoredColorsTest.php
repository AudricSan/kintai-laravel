<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * La migration 2026_10_15_000000 remplace les couleurs déjà en base qui ne sont pas #RGB / #RRGGBB
 * (comptes et types de shift : couleur par défaut ; rôles : null) et laisse intactes les valides.
 */
final class SanitizeStoredColorsTest extends TestCase
{
    private const MIGRATION = '/database/migrations/php/2026_10_15_000000_sanitize_stored_colors.php';

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

    public function testInvalidColorsAreReplacedAndValidOnesKept(): void
    {
        $db = $this->capsule->getConnection();
        foreach ([[1, '#112233'], [2, 'red;position:fixed'], [3, '']] as [$id, $color]) {
            $db->table('users')->insert([
                'id' => $id, 'email' => "u{$id}@kintai.test", 'first_name' => 'A', 'last_name' => 'B',
                'display_name' => 'A B', 'password_hash' => 'x', 'color' => $color,
            ]);
        }
        foreach ([[1, '#abc'], [2, '"><script>']] as [$id, $color]) {
            $db->table('shift_types')->insert(['id' => $id, 'code' => "T{$id}", 'name' => "T{$id}", 'start_time' => '08:00', 'end_time' => '16:00', 'color' => $color]);
        }
        foreach ([[1, '#445566'], [2, 'url(x)'], [3, null]] as [$id, $color]) {
            $db->table('roles')->insert(['id' => $id + 100, 'name' => "R{$id}", 'slug' => "r{$id}", 'color' => $color]);
        }

        $loader = new class($this->capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . self::MIGRATION)->up();

        $this->assertSame([1 => '#112233', 2 => '#3B82F6', 3 => '#3B82F6'], $db->table('users')->orderBy('id')->pluck('color', 'id')->all());
        $this->assertSame([1 => '#abc', 2 => '#3B82F6'], $db->table('shift_types')->orderBy('id')->pluck('color', 'id')->all());
        $this->assertSame([101 => '#445566', 102 => null, 103 => null], $db->table('roles')->where('id', '>', 100)->orderBy('id')->pluck('color', 'id')->all());
    }
}
