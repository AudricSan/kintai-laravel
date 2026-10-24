<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Export;

use kintai\Contract\Extension\ExportDataset;
use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Export\ExportAccess;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Droits d'export : exporter les données des autres exige la permission du jeu de données dans chaque magasin ; chacun peut
 * exporter les siennes quand le jeu de données le prévoit ; les personnes demandées sont recoupées avec les membres autorisés.
 */
final class ExportAccessTest extends TestCase
{
    private const MANAGER  = ['id' => 9];
    private const EMPLOYEE = ['id' => 5];

    private ExportAccess $access;

    protected function setUp(): void
    {
        // Le gérant (9) a un rôle shifts.view + employees.view sur le magasin 3 seulement ; l'employé (5) n'a aucun rôle.
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturnCallback(
            fn(int $id): array => $id === 9 ? [['role_id' => 1, 'scope_type' => 'store', 'scope_id' => 3]] : []
        );
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 1, 'is_system' => 0]);
        $roles->method('getPermissions')->willReturn(['shifts.view', 'employees.view']);
        $roles->method('getGlobalPermissionKeys')->willReturn([]);

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findActive')->willReturn([['id' => 3], ['id' => 4]]);

        // Magasin 3 : employés 5 et 6 ; magasin 4 : employé 7. Le gérant est membre du 3.
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturnCallback(fn(int $store): array => match ($store) {
            3 => [['user_id' => 5], ['user_id' => 6], ['user_id' => 9]],
            4 => [['user_id' => 7]],
            default => [],
        });
        $storeUsers->method('findByUser')->willReturnCallback(fn(int $user): array => match ($user) {
            5 => [['store_id' => 3]],
            9 => [['store_id' => 3]],
            default => [],
        });

        $this->access = new ExportAccess(new PermissionService($assignments, $roles), $stores, $storeUsers);
    }

    private function dataset(string $permission, bool $self = false, bool $perPerson = true): ExportDataset
    {
        $provider = new class implements ExportRowsProvider {
            public function rows(ExportQuery $query): iterable
            {
                return [];
            }
        };

        return new ExportDataset('demo', 'demo_label', $permission, ['a' => 'demo_a'], $provider, selfService: $self, perPerson: $perPerson);
    }

    public function testAManagerOnlyReachesTheStoresWhereHeHoldsThePermission(): void
    {
        $this->assertSame([3], $this->access->permittedStoreIds(self::MANAGER, 'shifts.view'));
        $this->assertSame([], $this->access->permittedStoreIds(self::MANAGER, 'payroll.export'));
        $this->assertSame([], $this->access->permittedStoreIds(self::EMPLOYEE, 'shifts.view'));
    }

    public function testAManagerExportsOneStoreOfHisScope(): void
    {
        $query = $this->access->query(self::MANAGER, $this->dataset('shifts.view'), ExportAccess::SCOPE_STORE, 3, [], '2026-11-01', '2026-11-30');

        $this->assertSame([3], $query->storeIds);
        $this->assertNull($query->userIds, 'aucune personne cochée : tout le magasin');
    }

    public function testAManagerCannotExportAStoreOutsideHisScope(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->access->query(self::MANAGER, $this->dataset('shifts.view'), ExportAccess::SCOPE_STORE, 4, [], '2026-11-01', '2026-11-30');
    }

    public function testAllMyStoresMeansOnlyThePermittedOnes(): void
    {
        $query = $this->access->query(self::MANAGER, $this->dataset('shifts.view'), ExportAccess::SCOPE_ALL, null, [], '2026-11-01', '2026-11-30');

        $this->assertSame([3], $query->storeIds);
    }

    public function testRequestedPeopleAreLimitedToTheMembersOfThePermittedStores(): void
    {
        // 7 est membre du magasin 4 que le gérant n'a pas le droit d'exporter ; 99 n'existe pas.
        $query = $this->access->query(self::MANAGER, $this->dataset('shifts.view'), ExportAccess::SCOPE_STORE, 3, [5, 7, 99], '2026-11-01', '2026-11-30');

        $this->assertSame([5], $query->userIds);
    }

    public function testOnlyForeignPeopleGiveAnEmptyExportNotTheWholeStore(): void
    {
        $query = $this->access->query(self::MANAGER, $this->dataset('shifts.view'), ExportAccess::SCOPE_STORE, 3, [7], '2026-11-01', '2026-11-30');

        $this->assertSame([], $query->userIds);
        $this->assertFalse($query->includesUser(5));
    }

    public function testADatasetNotPerPersonIgnoresThePeopleFilter(): void
    {
        $query = $this->access->query(self::MANAGER, $this->dataset('shifts.view', perPerson: false), ExportAccess::SCOPE_STORE, 3, [5], '2026-11-01', '2026-11-30');

        $this->assertNull($query->userIds);
    }

    public function testAnEmployeeExportsHisOwnDataWhenTheDatasetAllowsIt(): void
    {
        $query = $this->access->query(self::EMPLOYEE, $this->dataset('shifts.view', self: true), ExportAccess::SCOPE_ME, null, [6], '2026-11-01', '2026-11-30');

        $this->assertSame([3], $query->storeIds);
        $this->assertSame([5], $query->userIds, 'les personnes demandées sont ignorées pour « moi »');
    }

    public function testAnEmployeeCannotExportOthersNorADatasetWithoutSelfService(): void
    {
        foreach ([[ExportAccess::SCOPE_STORE, 3, true], [ExportAccess::SCOPE_ALL, null, true], [ExportAccess::SCOPE_ME, null, false]] as [$scope, $store, $self]) {
            try {
                $this->access->query(self::EMPLOYEE, $this->dataset('shifts.view', self: $self), $scope, $store, [], '2026-11-01', '2026-11-30');
                $this->fail("L'employé n'aurait pas dû pouvoir exporter ($scope).");
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAvailableListsOnlyWhatTheUserMayExport(): void
    {
        $datasets = ['shifts' => $this->dataset('shifts.view', self: true), 'payroll' => $this->dataset('payroll.export')];

        $manager  = $this->access->available(self::MANAGER, $datasets);
        $employee = $this->access->available(self::EMPLOYEE, $datasets);

        $this->assertSame(['shifts'], array_keys($manager));
        $this->assertSame([3], $manager['shifts']['stores']);
        $this->assertSame(['shifts'], array_keys($employee));
        $this->assertSame([], $employee['shifts']['stores']);
        $this->assertTrue($employee['shifts']['self']);
    }

    public function testAnInvalidScopeIsRefused(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->access->query(self::MANAGER, $this->dataset('shifts.view'), 'tout', null, [], '2026-11-01', '2026-11-30');
    }
}
