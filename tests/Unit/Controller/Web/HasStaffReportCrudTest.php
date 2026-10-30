<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Http\Controllers\HasAdminAccess;
use App\Http\Controllers\Staff\HasStaffReportCrud;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class HasStaffReportCrudTest extends TestCase
{
    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
    }

    /** Contrôleur minimal utilisant le trait. */
    private function harness(): object
    {
        return new class() {
            use HasAdminAccess;
            use HasStaffReportCrud;

            protected function reportRepo(): object { return new \stdClass(); }
            protected function reportConfig(): array { return []; }
            protected function reportShowTitle(array $report): string { return ''; }
            protected function reportEditTitle(): string { return ''; }
            protected function reportEditExtras(int $storeId, array $report): array { return []; }

            public function filter(Request $r): array { return $this->storesAndFilter($r); }
            public function checkUser(int $u, int $s): void { $this->assertReportUserInStore($u, $s); }
        };
    }

    private function request(array $authUser, ?array $managed, int $storeFilter = 0): Request
    {
        $_GET = $storeFilter > 0 ? ['store_id' => (string) $storeFilter] : [];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/reports', 'SCRIPT_NAME' => '/index.php'];
        $req = Request::capture();
        $req->attributes->set('auth_user', $authUser);
        $req->attributes->set('managed_store_ids', $managed);
        return $req;
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    /** Audit du 13/10/2026 : un non-Owner à portée illimitée recevait une liste de magasins vide, puis un 403 au filtrage. */
    public function testUnrestrictedNonOwnerSeesAllStoresAndCanFilter(): void
    {
        kintai_test_stores(findAll: fn() => [['id' => 1], ['id' => 2]]);
        [$all, $queryIds, $filter] = $this->harness()->filter($this->request(['id' => 5], null, 2));

        $this->assertCount(2, $all);
        $this->assertSame([2], $queryIds);
        $this->assertSame(2, $filter);
    }

    public function testScopedManagerCannotFilterOnForeignStore(): void
    {
        kintai_test_stores(findById: fn(int $id) => ['id' => $id]);
        $this->expectException(ForbiddenException::class);
        $this->harness()->filter($this->request(['id' => 5], [1], 2));
    }

    public function testScopedManagerOnlySeesManagedStores(): void
    {
        kintai_test_stores(findById: fn(int $id) => ['id' => $id]);
        [$all, $queryIds] = $this->harness()->filter($this->request(['id' => 5], [1, 3]));

        $this->assertSame([1, 3], $queryIds);
        $this->assertCount(2, $all);
    }

    public function testReportUserMustBeStoreMember(): void
    {
        kintai_test_store_users(findMembership: fn() => null);
        $this->expectException(ValidationException::class);
        $this->harness()->checkUser(9, 1);
    }

    public function testReportUserAcceptedWhenStoreMemberOrEmpty(): void
    {
        kintai_test_store_users(findMembership: fn(int $s, int $u) => $u === 9 ? ['id' => 1] : null);
        $h = $this->harness();

        $h->checkUser(9, 1);
        $h->checkUser(0, 1);
        $this->addToAssertionCount(2);
    }

}
