<?php

declare(strict_types=1);

namespace Tests\Bundles\Timeclock\Controllers\Api;

use kintai\Bundles\Installed\Timeclock\Controllers\Api\TimeclockController;
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
 * Régression (audit du 03/10/2026), reprise du Core à l'extraction : les API de bundles fusionnaient le JSON brut du
 * client dans save() ; un pointage pouvait viser un magasin dont l'employé n'est pas membre, ou être déplacé vers un
 * autre magasin ou employé.
 *
 * L'appelant (id 2) est un employé du magasin 5 ; le magasin 6 ne le concerne pas.
 */
final class TimeclockMassAssignmentTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeclocks', 'timeclock'];
    }

    private const ME       = 2;
    private const MY_STORE = 5;
    private const OTHER    = 6;

    protected function setUp(): void
    {
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::MY_STORE]], findMembership: fn(int $storeId, int $userId): ?array => $storeId === self::MY_STORE && $userId === self::ME
                ? ['store_id' => $storeId, 'user_id' => $userId]
                : null);
    }

    protected function tearDown(): void
    {
    }

    /** @param list<string> $keys clés accordées sur le magasin 5 */
    private function permissions(array $keys): PermissionService
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => self::ME, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::MY_STORE],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => $keys, getGlobalPermissionKeys: fn() => []);
        return new PermissionService();
    }

    private function request(array $json, array $params = []): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => self::ME]);
        $req->setRouteParams($params);
        kintai_test_set_json($req, $json);
        return $req;
    }

    public function testClockInInAStoreTheEmployeeDoesNotBelongToIsForbidden(): void
    {
        $this->resetRows();
        $this->seedRows([]);
        $this->expectNoChange();
        $controller = new TimeclockController(new AuditLogger(), $this->permissions([]));

        $this->expectException(ForbiddenException::class);
        $controller->clockIn($this->request(['user_id' => self::ME, 'store_id' => self::OTHER]));
    }

    public function testTimeclockUpdateCannotMoveTheEntryToAnotherStoreOrUser(): void
    {
        $this->resetRows();
        $this->seedRows([['id' => 3, 'user_id' => 9, 'store_id' => self::MY_STORE]]);
        // Ligne 3 modifiée : le texte change, mais ni le magasin, ni l'employé, ni les colonnes calculées.
        $this->expectSaved(fn(array $d): bool => (int) $d['id'] === 3
                && $d['notes'] === 'x'
                && (int) $d['store_id'] === self::MY_STORE
                && (int) $d['user_id'] === 9
                && $d['duration_minutes'] === null
                && $d['clock_in_ip'] === null);
        $controller = new TimeclockController(new AuditLogger(), $this->permissions(['timeclock.update']));

        $controller->update($this->request(['notes' => 'x', 'duration_minutes' => 9999, 'clock_in_ip' => '6.6.6.6', 'store_id' => self::OTHER, 'user_id' => 1], ['id' => '3']));
    }
}
