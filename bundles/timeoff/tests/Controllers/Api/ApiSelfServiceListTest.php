<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff\Controllers\Api;

use kintai\Bundles\Installed\TimeOff\Controllers\Api\TimeoffRequestController;
use App\Auth\PermissionService;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Libre-service de l'API (audit n°8 de Kintai, I3/D3) : un employé sans permission lit ses propres éléments avec
 * ?user_id=<lui> (règle self de la route). Avant, restrictToScope() vidait toujours sa liste. Il ne voit jamais ceux des
 * autres ; un gérant garde sa vue.
 */
final class ApiSelfServiceListTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeoff_requests', 'timeoff'];
    }

    private const EMPLOYEE = 3;
    private const MANAGER  = 1;

    private function controller(): TimeoffRequestController
    {
        $items = [
            ['id' => 1, 'store_id' => 1, 'user_id' => self::EMPLOYEE],
            ['id' => 2, 'store_id' => 1, 'user_id' => 4],
            ['id' => 3, 'store_id' => 2, 'user_id' => self::EMPLOYEE],
        ];
        $this->resetRows();
        $this->seedRows($items);
        kintai_test_assignments(findByUser: fn(int $u) => $u === self::MANAGER
            ? [['id' => 1, 'user_id' => self::MANAGER, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]
            : []);
        kintai_test_roles(findById: fn() => ['id' => 2, 'is_system' => 0], getPermissions: fn() => ['timeoff.view'], getGlobalPermissionKeys: fn() => []);
        $permissions = new PermissionService();

        return new TimeoffRequestController(new AuditLogger(), $permissions);
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
