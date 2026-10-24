<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Services\AvailabilityGuard;
use PHPUnit\Framework\TestCase;

/** Avertissement « employé non disponible » : jamais un blocage, seulement une information pour le planning. */
final class AvailabilityGuardTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function guard(array $rows): AvailabilityGuard
    {
        $repo = $this->createStub(AvailabilityRepositoryInterface::class);
        $repo->method('findByUserAndStore')->willReturn($rows);

        return new AvailabilityGuard($repo);
    }

    public function testADayMarkedUnavailableIsReported(): void
    {
        // 2026-10-26 est un lundi (day_of_week 1).
        $guard = $this->guard([['day_of_week' => 1, 'is_available' => 0, 'start_time' => '00:00', 'end_time' => '23:59']]);

        $this->assertSame(1, $guard->unavailableWeekday(7, 1, '2026-10-26'));
    }

    public function testSundayIsSeven(): void
    {
        $guard = $this->guard([['day_of_week' => 7, 'is_available' => 0]]);

        $this->assertSame(7, $guard->unavailableWeekday(7, 1, '2026-10-25'));
    }

    public function testAnAvailableDayIsNotReported(): void
    {
        $guard = $this->guard([['day_of_week' => 1, 'is_available' => 1, 'start_time' => '09:00', 'end_time' => '17:00']]);

        $this->assertNull($guard->unavailableWeekday(7, 1, '2026-10-26'));
    }

    public function testAnotherWeekdayIsNotReported(): void
    {
        $guard = $this->guard([['day_of_week' => 2, 'is_available' => 0]]);

        $this->assertNull($guard->unavailableWeekday(7, 1, '2026-10-26'));
    }

    public function testNothingIsReportedWhenTheEmployeeNeverFilledTheirAvailability(): void
    {
        $this->assertNull($this->guard([])->unavailableWeekday(7, 1, '2026-10-26'));
    }

    public function testInvalidInputIsIgnored(): void
    {
        $guard = $this->guard([['day_of_week' => 1, 'is_available' => 0]]);

        $this->assertNull($guard->unavailableWeekday(0, 1, '2026-10-26'));
        $this->assertNull($guard->unavailableWeekday(7, 0, '2026-10-26'));
        $this->assertNull($guard->unavailableWeekday(7, 1, 'pas une date'));
    }
}
