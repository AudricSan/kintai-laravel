<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\Migration;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/** Migration 2026_10_18_000001 : noms affichés vides complétés en « Nom Prénom ». */
final class FillEmptyUserDisplayNamesTest extends TestCase
{
    public function testEmptyDisplayNamesAreFilledAndOthersKept(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner, 'capsule'))->setValue($runner, $capsule);
        (new \ReflectionProperty($runner, 'migrationsPath'))->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');
        $runner->run();

        $db = $capsule->getConnection();
        $insert = fn(int $id, string $display, string $first, string $last) => $db->table('users')->insert([
            'id' => $id, 'email' => "u{$id}@kintai.test", 'password_hash' => 'x',
            'first_name' => $first, 'last_name' => $last, 'display_name' => $display,
        ]);
        $insert(1, '', 'Aiko', 'Sato');
        $insert(2, '   ', '愛子', '佐藤');
        $insert(3, 'Aiko S.', 'Aiko', 'Sato');
        $insert(4, '', '', '');

        /** @var Migration $migration */
        $migration = (function () use ($capsule) {
            $this->capsule = $capsule;
            return require dirname(__DIR__, 2) . '/database/migrations/php/2026_10_18_000001_fill_empty_user_display_names.php';
        })->call(new class { public $capsule; });
        $migration->up();

        $names = $db->table('users')->orderBy('id')->pluck('display_name')->all();
        $this->assertSame(['Sato Aiko', '佐藤 愛子', 'Aiko S.', ''], $names);
    }
}
