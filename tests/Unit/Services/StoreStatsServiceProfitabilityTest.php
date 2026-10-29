<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Contract\Provider\DailySales;
use kintai\Contract\Provider\SalesProvider;
use App\Bundles\Bundle;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use App\Extension\Sales;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\ShiftRepositoryInterface;
use App\Repositories\ShiftTypeRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\UserShiftTypeRateRepositoryInterface;
use App\Services\RoleAssignmentSyncService;
use App\Services\StoreStatsService;

/**
 * storeProfitability() : ventes, coût saisi et clients du jour lus auprès des fournisseurs de ventes (contrat 1.14,
 * daily-report), qui renvoient déjà des valeurs du jour (la conversion des saisies cumulées est testée côté
 * fournisseur) ; sans fournisseur, aucune ligne.
 */
final class StoreStatsServiceProfitabilityTest extends TestCase
{
    private function service(?Sales $sales): StoreStatsService
    {
        $shifts     = $this->createStub(ShiftRepositoryInterface::class);
        $shifts->method('findByStore')->willReturn([]);
        $shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $shiftTypes->method('findByStore')->willReturn([]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturn([]);

        return new StoreStatsService(
            $this->createStub(StoreRepositoryInterface::class),
            $shifts,
            $shiftTypes,
            $storeUsers,
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            new RoleAssignmentSyncService(
                $this->createStub(RoleRepositoryInterface::class),
                $this->createStub(RoleAssignmentRepositoryInterface::class),
            ),
            $sales,
        );
    }

    /** Ventes fournies par un bundle actif (comme daily-report), en valeurs du jour. */
    private function salesOf(array $days): Sales
    {
        $provider = new class($days) implements SalesProvider {
            public function __construct(private readonly array $days) {}

            public function dailySales(int $storeId, string $from, string $to, ?array $statuses = null): array
            {
                return $this->days;
            }
        };
        $bundle = new class($provider) extends Bundle {
            public function __construct(private readonly SalesProvider $provider) {}

            public function getName(): string
            {
                return 'daily-report';
            }

            public function register(): void {}

            public function salesProviders(): array
            {
                return [$this->provider];
            }
        };
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['daily-report' => $bundle]);

        return new Sales(new ExtensionRegistry($manager));
    }

    public function testRowsAndTotalsComeFromTheDayValuesOfTheProviders(): void
    {
        $result = $this->service($this->salesOf([
            new DailySales('2026-07-01', 'validated', 1000.0, 200.0, 10, 5.0),
            new DailySales('2026-07-02', 'validated', 1100.0, 210.0, 12, 6.0),
            new DailySales('2026-07-03', 'submitted', 1200.0, 200.0, 11, 4.0),
        ]))->storeProfitability(1, '2026-07-01', '2026-07-03');

        $this->assertSame(3300.0, $result['totalSales']);
        $this->assertSame(610.0, $result['totalLaborMnl']);
        $this->assertSame(33, $result['totalCustomers']);
        $this->assertSame([1000.0, 1100.0, 1200.0], array_column($result['rows'], 'sales'));
        $this->assertSame(['validated', 'validated', 'submitted'], array_column($result['rows'], 'status'));
        $this->assertSame(['2026-07' => 3300.0], $result['salesByMonth']);
    }

    public function testNoProviderMeansNoRows(): void
    {
        $result = $this->service(new Sales())->storeProfitability(1, '2026-07-01', '2026-07-03');

        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['totalSales']);
    }
}
