<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use App\Services\RoleAssignmentSyncService;
use App\Services\StoreStatsService;
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
    use \Tests\Support\UserRateExpectations;
    private ?StoreStatsService $service = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): StoreStatsService
    {
        if ($this->service === null) {
            $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
            $users = $this->createStub(UserRepositoryInterface::class);
            $storeUsers->method('findByStore')->willReturn([['user_id' => 10]]);
            kintai_test_shift_types([]);
            $users->method('findAll')->willReturn([
                ['id' => 10, 'first_name' => 'Aiko', 'last_name' => 'Sato', 'email' => 'a@kintai.test', 'password_hash' => '$2y$12$secret'],
            ]);
            $this->service = new StoreStatsService(
                $this->createStub(StoreRepositoryInterface::class),
                $storeUsers,
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

    /** Requêtes SELECT émises sur la table des shifts pendant $work. @return list<string> */
    private function shiftQueries(callable $work): array
    {
        $db = \Illuminate\Support\Facades\DB::connection();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $work();
        $db->disableQueryLog();

        return array_values(array_filter(array_column($db->getQueryLog(), 'query'), fn(string $q): bool => str_starts_with($q, 'select') && str_contains($q, 'from "shifts"')));
    }

    public function testTheWholeHistoryIsNeverLoaded(): void
    {
        kintai_test_shifts([]);

        $queries = $this->shiftQueries(fn() => $this->service()->storeStats(1, 30));

        $this->assertCount(1, $queries, 'une seule lecture, bornée par date');
        $this->assertStringContainsString('"shift_date" between', $queries[0]);
    }

    public function testTheSameStatsAreComputedOnlyOncePerRequest(): void
    {
        kintai_test_shifts([]);

        $service = $this->service();
        $first   = $service->storeStats(1, 30);
        $queries = $this->shiftQueries(function () use ($service, &$second): void {
            $second = $service->storeStats(1, 30);
        });

        $this->assertSame($first, $second);
        $this->assertSame([], $queries, 'le second appel vient du cache');
    }

    public function testDifferentArgumentsAreNotServedFromTheSameCacheEntry(): void
    {
        kintai_test_shifts([]);

        $service = $this->service();
        $queries = $this->shiftQueries(function () use ($service): void {
            $service->storeStats(1, 30);
            $service->storeStats(1, 180);
            $service->storeStats(1, 30, 240, 600);
        });

        $this->assertCount(3, $queries);
    }

    public function testCurrentAndPreviousPeriodsAreSplitFromTheSingleRead(): void
    {
        // 2 shifts dans la période courante, 1 dans la précédente, 1 supprimé : même découpage qu'avant.
        $deleted = array_merge($this->shift(4, date('Y-m-d', strtotime('-2 days'))), ['deleted_at' => '2026-01-01 00:00:00']);
        kintai_test_shifts([
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
        kintai_test_shifts([]);

        $user = $this->service()->storeStats(1, 30)['usersMap'][10];

        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertSame('Sato', $user['last_name'], 'les autres champs restent disponibles');
    }
}
