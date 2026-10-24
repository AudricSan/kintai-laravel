<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Le widget RH lit les pointages d'un store sur une période : index (store_id, shift_date) posé par la migration du
 * Core pour ne plus parcourir tout l'historique (la lecture par période, findByStoreBetween(), vit dans le bundle
 * timeclock et y est testée).
 */
final class TimeclockStoreDateIndexTest extends TestCase
{
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

    public function testAFreshInstallHasTheStoreDateIndex(): void
    {
        $columns = array_map(
            static fn(array $i): array => $i['columns'],
            $this->capsule->getConnection()->getSchemaBuilder()->getIndexes('timeclocks')
        );

        $this->assertContains(['store_id', 'shift_date'], $columns);
    }
}
