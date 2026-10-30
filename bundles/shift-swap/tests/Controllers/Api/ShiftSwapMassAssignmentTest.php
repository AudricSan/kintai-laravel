<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftSwap\Controllers\Api;

use kintai\Bundles\Installed\ShiftSwap\Controllers\Api\ShiftSwapRequestController;
use kintai\Bundles\Installed\ShiftSwap\Repositories\ShiftSwapRequestRepositoryInterface;
use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;

/**
 * Régression (audit du 03/10/2026), reprise du Core à l'extraction : la création par l'API fusionnait le JSON brut du
 * client dans save(), qui fait un upsert dès qu'un « id » est présent ; le statut ou la validation passaient tels quels.
 *
 * L'appelant (id 2) est un employé du magasin 5 ; le magasin 6 ne le concerne pas.
 */
final class ShiftSwapMassAssignmentTest extends TestCase
{
    private const ME       = 2;
    private const MY_STORE = 5;
    private const OTHER    = 6;

    private PermissionService $permissions;

    protected function setUp(): void
    {
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn([
            ['id' => 1, 'user_id' => self::ME, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::MY_STORE],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => ['swaps.create', 'swaps.update'], getGlobalPermissionKeys: fn() => []);
        $this->permissions = new PermissionService($assignments);
    }

    protected function tearDown(): void
    {
    }

    private function request(array $json): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => self::ME]);
        kintai_test_set_json($req, $json);
        return $req;
    }

    public function testSwapCreationIsForcedPendingAndIgnoresId(): void
    {
        $repo = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $repo->expects($this->once())->method('save')->with($this->callback(
            fn(array $d): bool => !array_key_exists('id', $d)
                && $d['status'] === 'pending'
                && !array_key_exists('approved_by_id', $d)
                && $d['store_id'] === self::MY_STORE
                && $d['requester_id'] === self::ME
        ))->willReturn(['id' => 12]);
        $controller = new ShiftSwapRequestController($repo, new AuditLogger(), $this->permissions);

        $controller->store($this->request([
            'id' => 3, 'store_id' => self::MY_STORE, 'status' => 'approved', 'approved_by_id' => 99,
            'requester_shift_id' => 4, 'target_shift_id' => 5,
        ]));
    }

    public function testSwapCreationForAnotherStoreIsForbidden(): void
    {
        $repo = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $repo->expects($this->never())->method('save');
        $controller = new ShiftSwapRequestController($repo, new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->store($this->request(['store_id' => self::OTHER, 'requester_shift_id' => 4]));
    }
}
