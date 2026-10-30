<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftSwap\Controllers\Api;

use kintai\Bundles\Installed\ShiftSwap\Controllers\Api\ShiftSwapRequestController;
use kintai\Bundles\Installed\ShiftSwap\Repositories\ShiftSwapRequestRepositoryInterface;
use App\Auth\PermissionService;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * Libre-service de l'API (audit n°8 de Kintai, I3/D3) : un employé sans permission lit ses propres éléments avec
 * ?user_id=<lui> (règle self de la route). Avant, restrictToScope() vidait toujours sa liste. Il ne voit jamais ceux des
 * autres ; un gérant garde sa vue.
 */
final class ApiSelfServiceListTest extends TestCase
{
    private const EMPLOYEE = 3;
    private const MANAGER  = 1;

    private function controller(): ShiftSwapRequestController
    {
        $items = [
            ['id' => 1, 'store_id' => 1, 'requester_id' => self::EMPLOYEE, 'target_id' => 4],
            ['id' => 2, 'store_id' => 1, 'requester_id' => 4, 'target_id' => 6],
            ['id' => 3, 'store_id' => 2, 'requester_id' => 5, 'target_id' => self::EMPLOYEE],
        ];
        $repo = $this->createStub(ShiftSwapRequestRepositoryInterface::class);
        $repo->method('findByRequester')->willReturnCallback(fn(int $u) => array_values(array_filter($items, fn($i) => $i['requester_id'] === $u)));
        $repo->method('findByTarget')->willReturnCallback(fn(int $u) => array_values(array_filter($items, fn($i) => $i['target_id'] === $u)));
        $repo->method('findByStore')->willReturnCallback(fn(int $s) => array_values(array_filter($items, fn($i) => $i['store_id'] === $s)));
        kintai_test_assignments(findByUser: fn(int $u) => $u === self::MANAGER
            ? [['id' => 1, 'user_id' => self::MANAGER, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]
            : []);
        kintai_test_roles(findById: fn() => ['id' => 2, 'is_system' => 0], getPermissions: fn() => ['swaps.view'], getGlobalPermissionKeys: fn() => []);
        $permissions = new PermissionService();

        return new ShiftSwapRequestController($repo, new AuditLogger(), $permissions);
    }

    /** @return list<int> */
    private function ids(int $caller, array $query): array
    {
        $_GET = $query;
        $request = Request::capture();
        $_GET = [];
        $request->attributes->set('auth_user', ['id' => $caller]);
        return array_map(fn($i) => (int) $i['id'], json_decode($this->controller()->index($request)->getContent(), true)['data']);
    }

    public function testAnEmployeeReadsTheirOwnItems(): void
    {
        $this->assertSame([1, 3], $this->ids(self::EMPLOYEE, ['user_id' => (string) self::EMPLOYEE]));
    }

    public function testAnEmployeeNeverSeesColleaguesItems(): void
    {
        $this->assertSame([1], $this->ids(self::EMPLOYEE, ['store_id' => '1']));
    }

    public function testAManagerStillSeesTheirStore(): void
    {
        $this->assertSame([1, 2], $this->ids(self::MANAGER, ['store_id' => '1']));
    }
}
