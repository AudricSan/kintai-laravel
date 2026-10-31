<?php

declare(strict_types=1);

namespace Tests\Bundles\DailyReport\Controllers\Api;

use kintai\Bundles\Installed\DailyReport\Controllers\Api\DailyReportController;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPermissionService;
use App\Auth\PermissionService;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Régression (audit du 03/10/2026), reprise du Core à l'extraction : la création par l'API fusionnait le JSON brut du
 * client dans save(), qui fait un upsert dès qu'un « id » est présent. Un POST portant un « id » écrasait donc le
 * rapport de quelqu'un d'autre, et l'auteur, le statut ou la validation passaient tels quels.
 *
 * L'appelant (id 2) est un employé du magasin 5 qui peut créer des rapports journaliers.
 */
final class DailyReportMassAssignmentTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['daily_reports', 'daily-report'];
    }

    private const ME       = 2;
    private const MY_STORE = 5;

    private PermissionService $permissions;

    protected function setUp(): void
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => self::ME, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::MY_STORE],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => ['daily_reports.create'], getGlobalPermissionKeys: fn() => []);
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

    public function testCreationForcesAuthorAndDraftAndIgnoresId(): void
    {
        $this->resetRows();
        $this->nextRowId(6); // le rapport créé prend 6 : jamais l'identifiant 1 envoyé par le client
        kintai_test_stores(findById: fn() => ['id' => self::MY_STORE, 'name' => 'A']);
        $this->expectSaved(fn(array $d): bool => (int) $d['id'] === 6
                && (int) $d['author_id'] === self::ME
                && $d['status'] === 'draft'
                && (int) $d['store_id'] === self::MY_STORE
                && $d['validated_by'] === null
                && $d['notes'] === 'RAS');
        $controller = new DailyReportController(new DailyReportPermissionService($this->permissions));

        $controller->store($this->request([
            'id' => 1, 'author_id' => 99, 'store_id' => self::MY_STORE, 'report_date' => '2026-10-01', 'notes' => 'RAS',
            'status' => 'validated', 'validated_by' => 99, 'is_finalized' => 1,
        ]));
    }
}
