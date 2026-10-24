<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Core\Services\StoreStatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * La vue financière du tableau de bord n'utilise que le coût par mois : costByMonth() le calcule sans le reste des
 * statistiques, et doit donner exactement le même résultat que storeStats()['costByMonth'].
 */
final class StoreStatsServiceCostByMonthTest extends TestCase
{
    private ShiftRepositoryInterface&Stub $shifts;
    private ?StoreStatsService $service = null;

    protected function setUp(): void
    {
        $this->shifts = $this->createStub(ShiftRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): StoreStatsService
    {
        if ($this->service === null) {
            $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
            $shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
            $users = $this->createStub(UserRepositoryInterface::class);
            $rates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);
            $storeUsers->method('findByStore')->willReturn([['user_id' => 10], ['user_id' => 11]]);
            $shiftTypes->method('findByStore')->willReturn([
                ['id' => 1, 'name' => 'Matin', 'hourly_rate' => 1100],
                ['id' => 2, 'name' => 'Soir', 'hourly_rate' => 1300],
            ]);
            $users->method('findAll')->willReturn([]);
            $rates->method('findByUser')->willReturnCallback(
                fn(int $uid) => $uid === 10 ? [['shift_type_id' => 2, 'hourly_rate' => 1500]] : []
            );
            $this->service = new StoreStatsService(
                $this->createStub(StoreRepositoryInterface::class),
                $this->shifts,
                $shiftTypes,
                $storeUsers,
                $rates,
                $users,
                new RoleAssignmentSyncService(
                    $this->createStub(RoleRepositoryInterface::class),
                    $this->createStub(RoleAssignmentRepositoryInterface::class),
                ),
            );
        }

        return $this->service;
    }

    private function shift(int $id, int $user, int $type, string $date, int $minutes, int $pause = 0, ?string $deleted = null): array
    {
        return [
            'id' => $id, 'store_id' => 1, 'user_id' => $user, 'shift_type_id' => $type, 'shift_date' => $date,
            'start_time' => '09:00', 'end_time' => '17:00', 'cross_midnight' => 0,
            'duration_minutes' => $minutes, 'pause_minutes' => $pause, 'deleted_at' => $deleted,
            'starts_at' => $date . ' 09:00:00', 'ends_at' => $date . ' 17:00:00',
        ];
    }

    public function testCostByMonthMatchesTheFullStatistics(): void
    {
        $d = static fn(int $daysAgo): string => date('Y-m-d', strtotime("-{$daysAgo} days"));
        $shifts = [
            $this->shift(1, 10, 1, $d(2), 480, 60),
            $this->shift(2, 10, 2, $d(20), 360),       // taux propre à l'employé
            $this->shift(3, 11, 2, $d(40), 300, 30),
            $this->shift(4, 12, 1, $d(70), 240),       // pas membre du store : taux du type
            $this->shift(5, 11, 1, $d(3), 480, 0, '2026-01-01 00:00:00'), // supprimé
        ];
        $this->shifts->method('findByStoreBetween')->willReturnCallback(
            fn(int $store, string $from, string $to) => array_values(array_filter($shifts, fn($s) => $s['shift_date'] >= $from && $s['shift_date'] <= $to))
        );

        foreach ([7, 30, 90, 180] as $period) {
            $this->assertSame(
                $this->service()->storeStats(1, $period)['costByMonth'],
                $this->service()->costByMonth(1, $period),
                "période $period"
            );
        }
    }

    public function testTheResultIsCachedForTheRequest(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shifts->expects($this->once())->method('findByStoreBetween')->willReturn([]);

        $this->service()->costByMonth(1, 180);
        $this->service()->costByMonth(1, 180);
    }
}
