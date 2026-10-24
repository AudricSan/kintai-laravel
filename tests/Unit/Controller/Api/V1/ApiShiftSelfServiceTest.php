<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Api\V1\ShiftController;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/v1/shifts en libre-service (audit n°8, I3, décision D3) : un employé sans shifts.view lit son propre planning
 * avec ?user_id=<lui>. Avant, la route exigeait shifts.view (403) et, même ouverte, le filtrage par permission vidait
 * la liste. Il ne voit jamais les shifts des autres.
 */
final class ApiShiftSelfServiceTest extends TestCase
{
    private const EMPLOYEE = 3;
    private const MANAGER  = 1;

    private function controller(): ShiftController
    {
        $shifts = $this->createStub(ShiftRepositoryInterface::class);
        $all = [
            ['id' => 1, 'store_id' => 1, 'user_id' => self::EMPLOYEE, 'shift_date' => '2026-10-20'],
            ['id' => 2, 'store_id' => 1, 'user_id' => 4, 'shift_date' => '2026-10-20'],
            ['id' => 3, 'store_id' => 2, 'user_id' => self::EMPLOYEE, 'shift_date' => '2026-10-21'],
        ];
        $shifts->method('findByUser')->willReturnCallback(fn(int $u) => array_values(array_filter($all, fn($s) => $s['user_id'] === $u)));
        $shifts->method('findByStore')->willReturnCallback(fn(int $st) => array_values(array_filter($all, fn($s) => $s['store_id'] === $st)));

        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturnCallback(fn(int $u) => $u === self::MANAGER
            ? [['id' => 1, 'user_id' => self::MANAGER, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]
            : []);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 2, 'is_system' => 0]);
        $roles->method('getPermissions')->willReturn(['shifts.view']);
        $roles->method('getGlobalPermissionKeys')->willReturn([]);

        return new ShiftController($shifts, new AuditLogger(), new PermissionService($assignments, $roles));
    }

    /** @return list<int> */
    private function ids(int $caller, array $query): array
    {
        $_GET = $query;
        $request = new Request();
        $_GET = [];
        $request->setAttribute('auth_user', ['id' => $caller]);
        return array_map(fn($s) => (int) $s['id'], json_decode($this->controller()->index($request)->body(), true)['data']);
    }

    public function testAnEmployeeReadsTheirOwnShiftsInEveryStore(): void
    {
        $this->assertSame([1, 3], $this->ids(self::EMPLOYEE, ['user_id' => (string) self::EMPLOYEE]));
    }

    public function testAnEmployeeNeverSeesColleaguesShifts(): void
    {
        $this->assertSame([1], $this->ids(self::EMPLOYEE, ['store_id' => '1', 'user_id' => (string) self::EMPLOYEE]));
    }

    public function testAManagerStillSeesTheirStore(): void
    {
        $this->assertSame([1, 2], $this->ids(self::MANAGER, ['store_id' => '1']));
    }
}
