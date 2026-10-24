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
 * Le tableau de bord demandait storeStats() trois fois par magasin et chaque appel relisait tout l'historique
 * des shifts du magasin, deux fois (2 s de chargement avec 4 500 shifts). Désormais : une seule lecture limitée à
 * la fenêtre utile (période précédente + période courante), et un résultat mis en cache pour la requête.
 */
final class StoreStatsServiceStoreStatsCacheTest extends TestCase
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
            $storeUsers->method('findByStore')->willReturn([['user_id' => 10]]);
            $shiftTypes->method('findByStore')->willReturn([]);
            $users->method('findAll')->willReturn([
                ['id' => 10, 'first_name' => 'Aiko', 'last_name' => 'Sato', 'email' => 'a@kintai.test', 'password_hash' => '$2y$12$secret'],
            ]);
            $rates->method('findByUser')->willReturn([]);
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

    private function shift(int $id, string $date, int $minutes = 480): array
    {
        return [
            'id' => $id, 'store_id' => 1, 'user_id' => 10, 'shift_date' => $date,
            'start_time' => '09:00', 'end_time' => '17:00', 'cross_midnight' => 0,
            'duration_minutes' => $minutes, 'pause_minutes' => 0, 'deleted_at' => null,
            'starts_at' => $date . ' 09:00:00', 'ends_at' => $date . ' 17:00:00',
        ];
    }

    public function testTheWholeHistoryIsNeverLoaded(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shifts->expects($this->never())->method('findByStore');
        $this->shifts->expects($this->once())->method('findByStoreBetween')
            ->with(1, date('Y-m-d', strtotime('-60 days')), date('Y-m-d'))
            ->willReturn([]);

        $this->service()->storeStats(1, 30);
    }

    public function testTheSameStatsAreComputedOnlyOncePerRequest(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shifts->expects($this->once())->method('findByStoreBetween')->willReturn([]);

        $first  = $this->service()->storeStats(1, 30);
        $second = $this->service()->storeStats(1, 30);

        $this->assertSame($first, $second);
    }

    public function testDifferentArgumentsAreNotServedFromTheSameCacheEntry(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shifts->expects($this->exactly(3))->method('findByStoreBetween')->willReturn([]);

        $this->service()->storeStats(1, 30);
        $this->service()->storeStats(1, 180);
        $this->service()->storeStats(1, 30, 240, 600);
    }

    public function testCurrentAndPreviousPeriodsAreSplitFromTheSingleRead(): void
    {
        // 2 shifts dans la période courante, 1 dans la précédente, 1 supprimé : même découpage qu'avant.
        $deleted = array_merge($this->shift(4, date('Y-m-d', strtotime('-2 days'))), ['deleted_at' => '2026-01-01 00:00:00']);
        $this->shifts->method('findByStoreBetween')->willReturn([
            $this->shift(1, date('Y-m-d', strtotime('-1 day'))),
            $this->shift(2, date('Y-m-d', strtotime('-10 days'))),
            $this->shift(3, date('Y-m-d', strtotime('-45 days'))),
            $deleted,
        ]);

        $stats = $this->service()->storeStats(1, 30);

        $this->assertSame(2, $stats['n']);
    }
    public function testTheUsersListNeverCarriesPasswordHashes(): void
    {
        // usersMap part jusqu'aux vues et aux exports : le hachage du mot de passe n'a rien à y faire.
        $this->shifts->method('findByStoreBetween')->willReturn([]);

        $user = $this->service()->storeStats(1, 30)['usersMap'][10];

        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertSame('Sato', $user['last_name'], 'les autres champs restent disponibles');
    }
}
