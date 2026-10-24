<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Request;
use kintai\UI\Controller\Web\HasAdminAccess;
use kintai\UI\Controller\Web\Staff\HasStaffReportCrud;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class HasStaffReportCrudTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private StoreUserRepositoryInterface&Stub $storeUsers;

    protected function setUp(): void
    {
        $this->stores     = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
    }

    /** Contrôleur minimal utilisant le trait ; $withStoreUsers=false simule une ancienne version de bundle. */
    private function harness(bool $withStoreUsers = true): object
    {
        $stores     = $this->stores;
        $storeUsers = $this->storeUsers;

        if ($withStoreUsers) {
            return new class($stores, $storeUsers) {
                use HasAdminAccess;
                use HasStaffReportCrud;

                public function __construct(
                    private readonly StoreRepositoryInterface $stores,
                    private readonly StoreUserRepositoryInterface $storeUsers,
                ) {}

                protected function reportRepo(): object { return new \stdClass(); }
                protected function reportConfig(): array { return []; }
                protected function reportShowTitle(array $report): string { return ''; }
                protected function reportEditTitle(): string { return ''; }
                protected function reportEditExtras(int $storeId, array $report): array { return []; }

                public function filter(Request $r): array { return $this->storesAndFilter($r); }
                public function checkUser(int $u, int $s): void { $this->assertReportUserInStore($u, $s); }
            };
        }

        return new class($stores) {
            use HasAdminAccess;
            use HasStaffReportCrud;

            public function __construct(private readonly StoreRepositoryInterface $stores) {}

            protected function reportRepo(): object { return new \stdClass(); }
            protected function reportConfig(): array { return []; }
            protected function reportShowTitle(array $report): string { return ''; }
            protected function reportEditTitle(): string { return ''; }
            protected function reportEditExtras(int $storeId, array $report): array { return []; }

            public function checkUser(int $u, int $s): void { $this->assertReportUserInStore($u, $s); }
        };
    }

    private function request(array $authUser, ?array $managed, int $storeFilter = 0): Request
    {
        $_GET = $storeFilter > 0 ? ['store_id' => (string) $storeFilter] : [];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/reports', 'SCRIPT_NAME' => '/index.php'];
        $req = new Request();
        $req->setAttribute('auth_user', $authUser);
        $req->setAttribute('managed_store_ids', $managed);
        return $req;
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    /** Audit du 13/10/2026 : un non-Owner à portée illimitée recevait une liste de magasins vide, puis un 403 au filtrage. */
    public function testUnrestrictedNonOwnerSeesAllStoresAndCanFilter(): void
    {
        $this->stores->method('findAll')->willReturn([['id' => 1], ['id' => 2]]);

        [$all, $queryIds, $filter] = $this->harness()->filter($this->request(['id' => 5], null, 2));

        $this->assertCount(2, $all);
        $this->assertSame([2], $queryIds);
        $this->assertSame(2, $filter);
    }

    public function testScopedManagerCannotFilterOnForeignStore(): void
    {
        $this->stores->method('findById')->willReturnCallback(fn(int $id) => ['id' => $id]);

        $this->expectException(ForbiddenException::class);
        $this->harness()->filter($this->request(['id' => 5], [1], 2));
    }

    public function testScopedManagerOnlySeesManagedStores(): void
    {
        $this->stores->method('findById')->willReturnCallback(fn(int $id) => ['id' => $id]);

        [$all, $queryIds] = $this->harness()->filter($this->request(['id' => 5], [1, 3]));

        $this->assertSame([1, 3], $queryIds);
        $this->assertCount(2, $all);
    }

    public function testReportUserMustBeStoreMember(): void
    {
        $this->storeUsers->method('findMembership')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->harness()->checkUser(9, 1);
    }

    public function testReportUserAcceptedWhenStoreMemberOrEmpty(): void
    {
        $this->storeUsers->method('findMembership')->willReturnCallback(fn(int $s, int $u) => $u === 9 ? ['id' => 1] : null);
        $h = $this->harness();

        $h->checkUser(9, 1);
        $h->checkUser(0, 1);
        $this->addToAssertionCount(2);
    }

    /** Un bundle qui n'injecte pas encore storeUsers ne doit pas planter. */
    public function testToleratesBundlesWithoutStoreUsers(): void
    {
        $this->harness(false)->checkUser(9, 1);
        $this->addToAssertionCount(1);
    }
}
