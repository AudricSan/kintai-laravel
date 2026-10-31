<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftClaim\Controllers\Api;

use kintai\Bundles\Installed\ShiftClaim\Controllers\Api\ShiftClaimController;
use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Régression (audit du 03/10/2026), reprise du Core à l'extraction : la création par l'API fusionnait le JSON brut du
 * client dans save(), qui fait un upsert dès qu'un « id » est présent. Un POST portant un « id » écrasait la candidature
 * de quelqu'un d'autre, et le statut ou la résolution passaient tels quels.
 *
 * L'appelant (id 2) est un employé du magasin 5 ; le magasin 6 ne le concerne pas.
 */
final class ShiftClaimMassAssignmentTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['shift_claims', 'shift-claim'];
    }

    private const ME       = 2;
    private const MY_STORE = 5;
    private const OTHER    = 6;

    private PermissionService $permissions;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => self::ME, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::MY_STORE],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => ['open_shifts.approve'], getGlobalPermissionKeys: fn() => []);
        $this->permissions = new PermissionService();
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::MY_STORE]], findMembership: fn(int $storeId, int $userId): ?array => $storeId === self::MY_STORE && $userId === self::ME
                ? ['store_id' => $storeId, 'user_id' => $userId]
                : null);
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

    public function testClaimCreationIsForcedPendingAndIgnoresId(): void
    {
        $this->resetRows();
        $this->nextRowId(50); // la candidature créée prend 50 : jamais l'identifiant 1 envoyé par le client
        $this->expectSaved(fn(array $d): bool => (int) $d['id'] === 50
                && $d['status'] === 'pending'
                && $d['resolved_by'] === null
                && (int) $d['user_id'] === self::ME
                && (int) $d['shift_id'] === 8);
        kintai_test_shifts([['id' => 8, 'store_id' => self::MY_STORE, 'is_open' => 1, 'user_id' => null]]);
        $controller = new ShiftClaimController($this->permissions);

        $controller->store($this->request([
            'id' => 1, 'user_id' => self::ME, 'store_id' => self::MY_STORE, 'shift_id' => 8,
            'status' => 'approved', 'resolved_by' => 99,
        ]));
    }

    public function testClaimCreationForAnotherStoreIsForbidden(): void
    {
        $this->resetRows();
        $this->expectNoChange();
        $controller = new ShiftClaimController($this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->store($this->request(['user_id' => self::ME, 'store_id' => self::OTHER, 'shift_id' => 8]));
    }
}
