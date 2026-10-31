<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftSwap\Controllers\Api;

use kintai\Bundles\Installed\ShiftSwap\Controllers\Api\ShiftSwapRequestController;
use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Models\RoleAssignment;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Régression (audit du 03/10/2026), reprise du Core à l'extraction : la création par l'API fusionnait le JSON brut du
 * client dans save(), qui fait un upsert dès qu'un « id » est présent ; le statut ou la validation passaient tels quels.
 *
 * L'appelant (id 2) est un employé du magasin 5 ; le magasin 6 ne le concerne pas.
 */
final class ShiftSwapMassAssignmentTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['shift_swap_requests', 'shift-swap'];
    }

    private const ME       = 2;
    private const MY_STORE = 5;
    private const OTHER    = 6;

    private PermissionService $permissions;

    protected function setUp(): void
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => self::ME, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::MY_STORE],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => ['swaps.create', 'swaps.update'], getGlobalPermissionKeys: fn() => []);
        $this->permissions = new PermissionService();
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
        $this->resetRows();
        $this->nextRowId(50); // la demande créée prend 50 : jamais l'identifiant 3 envoyé par le client
        $this->expectSaved(fn(array $d): bool => (int) $d['id'] === 50
                && $d['status'] === 'pending'
                && $d['approved_by_id'] === null
                && (int) $d['store_id'] === self::MY_STORE
                && (int) $d['requester_id'] === self::ME);
        $controller = new ShiftSwapRequestController(new AuditLogger(), $this->permissions);

        $controller->store($this->request([
            'id' => 3, 'store_id' => self::MY_STORE, 'status' => 'approved', 'approved_by_id' => 99,
            'requester_shift_id' => 4, 'target_shift_id' => 5,
        ]));
    }

    public function testSwapCreationForAnotherStoreIsForbidden(): void
    {
        $this->resetRows();
        $this->expectNoChange();
        $controller = new ShiftSwapRequestController(new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->store($this->request(['store_id' => self::OTHER, 'requester_shift_id' => 4]));
    }
}
