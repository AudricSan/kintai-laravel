<?php

namespace Tests\Unit;

use App\Stores\StoreFeatureRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class StoreFeatureRegistryTest extends TestCase
{
    public function test_registers_features_in_order_and_refuses_duplicates(): void
    {
        $registry = new StoreFeatureRegistry;
        $registry->register('shifts', 'Planning');
        $registry->register('payroll', 'Payroll');

        $this->assertSame(['shifts', 'payroll'], $registry->keys());
        $this->assertSame(['shifts' => 'Planning', 'payroll' => 'Payroll'], $registry->all());
        $this->assertTrue($registry->has('payroll'));
        $this->assertFalse($registry->has('other'));

        $this->expectException(InvalidArgumentException::class);
        $registry->register('shifts', 'Again');
    }

    public function test_the_core_registers_the_planning_feature(): void
    {
        $this->assertSame('Planning', app(StoreFeatureRegistry::class)->all()['shifts']);
    }
}
