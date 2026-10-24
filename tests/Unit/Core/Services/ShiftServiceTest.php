<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Services\ShiftService;
use kintai\Tests\Support\FakeAbsenceProvider;
use PHPUnit\Framework\TestCase;

final class ShiftServiceTest extends TestCase
{
    private ShiftService $service;

    protected function tearDown(): void
    {
        // Congés déclarés par les tests (FakeAbsenceProvider) : ne pas les laisser au conteneur partagé.
        $instances = new \ReflectionProperty(Container::class, 'instances');
        $all = $instances->getValue(Container::getInstance());
        unset($all[BundleManager::class], $all[ExtensionRegistry::class]);
        $instances->setValue(Container::getInstance(), $all);
    }

    protected function setUp(): void
    {

        $this->service = new ShiftService(
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        );
    }

    public function testGetTimeoffForCalendarExpandsApprovedRequestOverEachDay(): void
    {
        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'approved', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03', 'type' => 'vacation'],
        ]);

        $result = $this->service->getTimeoffForCalendar([5], '2026-08-01', '2026-08-31');

        $this->assertArrayHasKey('2026-08-01', $result);
        $this->assertArrayHasKey('2026-08-02', $result);
        $this->assertArrayHasKey('2026-08-03', $result);
        $this->assertArrayNotHasKey('2026-08-04', $result);
        $this->assertSame(5, $result['2026-08-01'][0]['user_id']);
        $this->assertSame('vacation', $result['2026-08-01'][0]['type']);
    }

    public function testGetTimeoffForCalendarIgnoresNonApprovedRequests(): void
    {
        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'pending', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03', 'type' => 'vacation'],
        ]);

        $result = $this->service->getTimeoffForCalendar([5], '2026-08-01', '2026-08-31');

        $this->assertSame([], $result);
    }

    public function testGetTimeoffForCalendarClipsRangeToRequestedWindow(): void
    {
        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'approved', 'start_date' => '2026-07-28', 'end_date' => '2026-08-02', 'type' => 'sick'],
        ]);

        $result = $this->service->getTimeoffForCalendar([5], '2026-08-01', '2026-08-31');

        $this->assertArrayNotHasKey('2026-07-28', $result);
        $this->assertArrayHasKey('2026-08-01', $result);
        $this->assertArrayHasKey('2026-08-02', $result);
    }
}
