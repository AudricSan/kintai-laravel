<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff\Controllers\Api;

use kintai\Bundles\Installed\TimeOff\Controllers\Api\TimeoffRequestController;
use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Régression (audit du 03/10/2026), reprise du Core à l'extraction : la création par l'API fusionnait le JSON brut du
 * client dans save(), qui fait un upsert dès qu'un « id » est présent ; un employé pouvait poster status: approved
 * (auto-approbation), processed_by, ou écraser la demande de quelqu'un d'autre.
 *
 * L'appelant (id 2) est un employé du magasin 5 ; le magasin 6 ne le concerne pas.
 */
final class TimeoffMassAssignmentTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeoff_requests', 'timeoff'];
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
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => ['timeoff.create', 'timeoff.update'], getGlobalPermissionKeys: fn() => []);
        $this->permissions = new PermissionService();
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::MY_STORE]], findMembership: fn(int $storeId, int $userId): ?array => $storeId === self::MY_STORE && $userId === self::ME
                ? ['store_id' => $storeId, 'user_id' => $userId]
                : null);
    }

    protected function tearDown(): void
    {
    }

    private function request(array $json, array $params = []): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => self::ME]);
        $req->setRouteParams($params);
        kintai_test_set_json($req, $json);
        return $req;
    }

    public function testTimeoffCreationCannotSelfApproveNorOverwriteAnotherRequest(): void
    {
        $this->resetRows();
        // Ligne créée : jamais l'identifiant 7 envoyé, ni approuvée, ni traitée par quelqu'un.
        $this->expectSaved(fn(array $d): bool => (int) $d['id'] !== 7
                && $d['status'] === 'pending'
                && $d['processed_by'] === null
                && $d['admin_note'] === null
                && (int) $d['user_id'] === self::ME
                && (int) $d['store_id'] === self::MY_STORE
                && $d['reason'] === 'vacances');
        $controller = new TimeoffRequestController(new AuditLogger(), $this->permissions);

        $response = $controller->store($this->request([
            'id' => 7, 'user_id' => self::ME, 'store_id' => self::MY_STORE, 'reason' => 'vacances',
            'status' => 'approved', 'processed_by' => 99, 'admin_note' => 'ok',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-02',
        ]));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testTimeoffCreationForAnotherStoreIsForbidden(): void
    {
        $this->resetRows();
        $this->expectNoChange();
        $controller = new TimeoffRequestController(new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->store($this->request(['user_id' => self::ME, 'store_id' => self::OTHER]));
    }

    public function testTimeoffUpdateKeepsOwnerAndStoreAndRecordsWhoProcessedIt(): void
    {
        $this->resetRows();
        $this->seedRows([['id' => 7, 'user_id' => 9, 'store_id' => self::MY_STORE]]);
        // Ligne 7 modifiée : décision enregistrée, mais propriétaire et magasin inchangés.
        $this->expectSaved(fn(array $d): bool => (int) $d['id'] === 7
                && $d['status'] === 'approved'
                && (int) $d['processed_by'] === self::ME
                && (int) $d['store_id'] === self::MY_STORE
                && (int) $d['user_id'] === 9);
        $controller = new TimeoffRequestController(new AuditLogger(), $this->permissions);

        $controller->update($this->request(['status' => 'approved', 'store_id' => self::OTHER, 'user_id' => 1], ['id' => '7']));
    }
}
