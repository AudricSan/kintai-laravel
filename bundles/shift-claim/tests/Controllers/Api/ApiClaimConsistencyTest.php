<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftClaim\Controllers\Api;

use kintai\Bundles\Installed\ShiftClaim\Controllers\Api\ShiftClaimController;
use kintai\Bundles\Installed\ShiftClaim\Repositories\ShiftClaimRepositoryInterface;
use kintai\Bundles\Installed\ShiftClaim\Services\ShiftClaimApproval;
use App\Auth\PermissionService;
use App\Exceptions\ValidationException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Services\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * API cohérente avec le web (audit n°8 de Kintai) : une candidature ne vise qu'un shift publié du magasin indiqué, et
 * l'approuver attribue le shift (avant : statut changé, shift resté ouvert à son ancien titulaire).
 */
final class ApiClaimConsistencyTest extends TestCase
{
    private const MANAGER = 1;
    private const EMPLOYEE = 3;

    private PermissionService $permissions;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
        kintai_test_assignments(findByUser: fn(int $u) => $u === self::MANAGER
            ? [['id' => 1, 'user_id' => self::MANAGER, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]
            : []);
        kintai_test_roles(findById: fn() => ['id' => 2, 'is_system' => 0], getPermissions: fn() => ['open_shifts.approve', 'open_shifts.view'], getGlobalPermissionKeys: fn() => []);
        $this->permissions = new PermissionService();
        kintai_test_store_users(findMembership: fn() => ['store_id' => 1, 'user_id' => self::EMPLOYEE]);
    }

    private function request(int $caller, array $json, array $params = []): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $caller]);
        $req->setRouteParams($params);
        kintai_test_set_json($req, $json);
        return $req;
    }

    /** @return array<string, array{array<string, mixed>|null}> */
    public static function unavailableShifts(): array
    {
        return [
            'inexistant'        => [null],
            'non publié'        => [['id' => 8, 'store_id' => 1, 'is_open' => 0, 'user_id' => null]],
            'autre magasin'     => [['id' => 8, 'store_id' => 2, 'is_open' => 1, 'user_id' => null]],
            'son propre shift'  => [['id' => 8, 'store_id' => 1, 'is_open' => 1, 'user_id' => self::EMPLOYEE]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableShifts')]
    public function testAClaimNeedsAPublishedShiftOfThatStore(?array $shift): void
    {
        $claims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $claims->expects($this->never())->method('save');
        kintai_test_shifts($shift === null ? [] : [$shift]);

        $this->expectException(ValidationException::class);
        (new ShiftClaimController($claims, $this->permissions))
            ->store($this->request(self::EMPLOYEE, ['store_id' => 1, 'shift_id' => 8]));
    }

    public function testApprovingThroughTheApiAssignsTheShift(): void
    {
        $claim = ['id' => 10, 'shift_id' => 8, 'user_id' => self::EMPLOYEE, 'store_id' => 1, 'status' => 'pending'];
        $shift = ['id' => 8, 'store_id' => 1, 'is_open' => 1, 'user_id' => 4];
        $claims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $claims->method('findById')->willReturn($claim);
        $claims->method('findByShift')->willReturn([$claim]);
        $claims->expects($this->once())->method('approveIfPending')->willReturn(['status' => 'approved'] + $claim);
        kintai_test_shifts([$shift]);
        $notifs = $this->createStub(NotificationService::class);
        $notified = [];
        $notifs->method('notify')->willReturnCallback(function (int $u, string $type) use (&$notified) {
            $notified[] = [$u, $type];
        });
        $approval = new ShiftClaimApproval($claims, $notifs);

        $response = (new ShiftClaimController($claims, $this->permissions, $approval))
            ->update($this->request(self::MANAGER, ['status' => 'approved'], ['id' => '10']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([[self::EMPLOYEE, 'shift_claim_approved'], [4, 'shift_claim_handed_over']], $notified);
        $this->assertSame(self::EMPLOYEE, \App\Models\Shift::findById(8)['user_id']);
    }

    public function testApprovingAClosedShiftIsAConflict(): void
    {
        $claim = ['id' => 10, 'shift_id' => 8, 'user_id' => self::EMPLOYEE, 'store_id' => 1, 'status' => 'pending'];
        $claims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $claims->method('findById')->willReturn($claim);
        $claims->expects($this->never())->method('approveIfPending');
        kintai_test_shifts([['id' => 8, 'store_id' => 1, 'is_open' => 0, 'user_id' => 5]]);
        $approval = new ShiftClaimApproval($claims,
            $this->createStub(NotificationService::class));

        $response = (new ShiftClaimController($claims, $this->permissions, $approval))
            ->update($this->request(self::MANAGER, ['status' => 'approved'], ['id' => '10']));

        $this->assertSame(409, $response->getStatusCode());
    }
}
