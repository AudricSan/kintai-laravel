<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
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
        $this->capsule = kintai_test_database();

        kintai_test_migrate();
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
