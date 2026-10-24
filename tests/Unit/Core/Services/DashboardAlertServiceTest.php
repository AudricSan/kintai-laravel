<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Contract\Provider\PendingRequest;
use kintai\Contract\Provider\PendingRequestsProvider;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\PendingRequests;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\DashboardAlertService;
use kintai\Tests\Support\FakeAttendanceProvider;
use kintai\Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\TestCase;

/**
 * Les fixtures sont des propriétés mutables lues via willReturnCallback (plutôt que
 * plusieurs ->method()->willReturn() successifs sur le même mock) : PHPUnit ne garantit
 * pas qu'un second stub configuré dans un test override celui du setUp() pour la même
 * méthode sans contrainte with(), donc chaque test doit pouvoir personnaliser les
 * données sans re-stubber le mock.
 */
final class DashboardAlertServiceTest extends TestCase
{
    /** @var array<string, array> date (Y-m-d) => shifts renvoyés par findAllByDate() pour cette date */
    private array $shiftsByDate = [];

    /** @var array<int, array> store_id => membres (store_user) */
    private array $storeMembers = [];

    /** @var array<int, array|null> user_id => utilisateur */
    private array $usersById = [];

    /** @var array<string, list<PendingRequest>> clé du fournisseur de demandes (timeoff, swaps…) => demandes en attente */
    private array $pendingFixture = [];
    private array $timeclocksFixture = [];

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Container::class, 'instance'))->setValue(null, null);
    }

    protected function setUp(): void
    {
        $this->shiftsByDate = [];
        $this->storeMembers = [];
        $this->usersById = [];
        $this->pendingFixture = [];
        $this->timeclocksFixture = [];
    }

    private function service(): DashboardAlertService
    {
        $shifts = $this->createStub(ShiftRepositoryInterface::class);
        $shifts->method('findAllByDate')->willReturnCallback(
            fn(string $date) => $this->shiftsByDate[$date] ?? []
        );

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(
            fn(int $id) => $this->usersById[$id] ?? null
        );

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturnCallback(
            fn(int $storeId) => $this->storeMembers[$storeId] ?? []
        );

        // Demandes en attente fournies par des bundles actifs (fournisseurs du contrat 1.9).
        $providers = [];
        foreach ($this->pendingFixture as $key => $requests) {
            $providers[$key] = [self::provider($key, $requests)];
        }
        // Pointages fournis par timeclock (contrat 1.11), lus par le Core à travers le registre des extensions.
        $attendance = $this->timeclocksFixture === [] ? [] : ['timeclock' => [new FakeAttendanceProvider($this->timeclocksFixture)]];
        $manager = FakeBundleManagerFactory::withActiveSlugs(array_unique([...array_keys($providers), ...array_keys($attendance)]), [], $providers, $attendance);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        return new DashboardAlertService($shifts, $users, $storeUsers, new PendingRequests(new ExtensionRegistry($manager)));
    }

    /** @param list<PendingRequest> $requests */
    private static function provider(string $key, array $requests): PendingRequestsProvider
    {
        return new class($key, $requests) implements PendingRequestsProvider {
            public function __construct(private readonly string $k, private readonly array $r) {}
            public function key(): string { return $this->k; }
            public function label(): string { return 'pending_' . $this->k; }
            public function emptyLabel(): string { return 'none'; }
            public function permission(): string { return $this->k . '.view'; }
            public function storeFeature(): ?string { return null; }
            public function order(): int { return 10; }
            public function manageUrl(): string { return '/' . $this->k; }

            public function pending(?array $storeIds): array
            {
                return array_values(array_filter($this->r, fn(PendingRequest $r): bool => $storeIds === null || in_array($r->storeId, $storeIds, true)));
            }
        };
    }

    public function testDetectsUnfilledShiftInScopeWithinUpcomingWindow(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->shiftsByDate[$tomorrow] = [
            ['id' => 42, 'store_id' => 1, 'user_id' => null, 'shift_date' => $tomorrow, 'start_time' => '09:00', 'end_time' => '17:00'],
        ];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertCount(1, $result['unfilled_shifts']);
        $this->assertSame(42, $result['unfilled_shifts'][0]['id']);
        $this->assertSame('Store 1', $result['unfilled_shifts'][0]['store_name']);
    }

    public function testIgnoresUnfilledShiftOutsideScope(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->shiftsByDate[$tomorrow] = [
            ['id' => 42, 'store_id' => 2, 'user_id' => null, 'shift_date' => $tomorrow, 'start_time' => '09:00', 'end_time' => '17:00'],
        ];

        $result = $this->service()->build([1], [1 => 'Store 1', 2 => 'Store 2']);

        $this->assertSame([], $result['unfilled_shifts']);
    }

    public function testIgnoresAssignedShiftAsUnfilled(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->shiftsByDate[$tomorrow] = [
            ['id' => 42, 'store_id' => 1, 'user_id' => 7, 'shift_date' => $tomorrow, 'start_time' => '09:00', 'end_time' => '17:00'],
        ];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertSame([], $result['unfilled_shifts']);
    }

    public function testDetectsActiveUserWithoutShiftThisWeek(): void
    {
        // Aucun shift cette semaine (shiftsByDate reste vide pour toutes les dates).
        $this->storeMembers[1] = [['user_id' => 7]];
        $this->usersById[7] = ['id' => 7, 'display_name' => 'Bob', 'is_active' => 1];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertCount(1, $result['users_without_shift']);
        $this->assertSame(7, $result['users_without_shift'][0]['id']);
    }

    public function testExcludesInactiveUserFromUsersWithoutShift(): void
    {
        $this->storeMembers[1] = [['user_id' => 7]];
        $this->usersById[7] = ['id' => 7, 'display_name' => 'Bob', 'is_active' => 0];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertSame([], $result['users_without_shift']);
    }

    public function testExcludesUserWithAShiftThisWeekFromUsersWithoutShift(): void
    {
        $monday = date('Y-m-d', strtotime('monday this week'));
        $this->shiftsByDate[$monday] = [
            ['id' => 1, 'store_id' => 1, 'user_id' => 7, 'shift_date' => $monday, 'start_time' => '09:00', 'end_time' => '17:00'],
        ];
        $this->storeMembers[1] = [['user_id' => 7]];
        $this->usersById[7] = ['id' => 7, 'display_name' => 'Bob', 'is_active' => 1];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertSame([], $result['users_without_shift']);
    }

    /** Demandes en attente depuis plus de 3 jours, de toutes sortes (congés, échanges, candidatures…), du magasin en scope. */
    public function testDetectsStaleRequestsOfEveryProvider(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-5 days'));
        $recent = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->pendingFixture = [
            'timeoff' => [new PendingRequest(1, 1, 5, $old), new PendingRequest(2, 1, 5, $recent), new PendingRequest(4, 2, 5, $old)],
            'swaps'   => [new PendingRequest(10, 1, 5, $old)],
            'open_shifts' => [new PendingRequest(20, 1, 6, $old)],
        ];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertCount(3, $result['stale_requests']);
        $this->assertEqualsCanonicalizing([1, 10, 20], array_column($result['stale_requests'], 'id'));
        $this->assertEqualsCanonicalizing(['timeoff', 'swaps', 'open_shifts'], array_column($result['stale_requests'], 'type'));
        $this->assertContains('pending_open_shifts', array_column($result['stale_requests'], 'label'));
        $this->assertSame(['Store 1'], array_values(array_unique(array_column($result['stale_requests'], 'store_name'))));
    }

    public function testDetectsStaleOpenTimeclock(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-13 hours'));
        $recent = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $this->timeclocksFixture = [
            ['id' => 1, 'store_id' => 1, 'user_id' => 7, 'clock_in_time' => $old, 'clock_out_time' => null],
            ['id' => 2, 'store_id' => 1, 'user_id' => 8, 'clock_in_time' => $recent, 'clock_out_time' => null],
            ['id' => 3, 'store_id' => 1, 'user_id' => 9, 'clock_in_time' => $old, 'clock_out_time' => $old],
        ];
        $this->usersById[7] = ['id' => 7, 'display_name' => 'Bob', 'is_active' => 1];

        $result = $this->service()->build([1], [1 => 'Store 1']);

        $this->assertCount(1, $result['stale_timeclocks']);
        $this->assertSame(1, $result['stale_timeclocks'][0]['id']);
        $this->assertSame('Bob', $result['stale_timeclocks'][0]['user_name']);
    }
}
