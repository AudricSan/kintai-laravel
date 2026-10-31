<?php

declare(strict_types=1);

namespace Tests\Bundles\Timeclock\Controllers\Api;


use kintai\Bundles\Installed\Timeclock\Controllers\Api\TimeclockController;
use App\Auth\PermissionService;
use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Régression (audit RBAC du 11/09/2026) : show/update/destroy ne re-vérifiaient
 * jamais que l'entrée de pointage chargée appartenait à un store géré par
 * l'appelant — voir le docblock de TimeclockController.
 */
final class TimeclockControllerTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeclocks', 'timeclock'];
    }

    private ?TimeclockController $controller = null;

    protected function setUp(): void
    {
        $this->resetRows();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): TimeclockController
    {
        if ($this->controller === null) {
            $this->controller  = new TimeclockController(
                new AuditLogger(),
                new PermissionService()
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    private function requestAsOwner(): Request
    {
        kintai_test_assignments(findByUser: fn($a0) => $a0 === 1 ? [
            ['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ] : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 10 ? ['id' => 10, 'is_system' => 1] : null);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);
        return $req;
    }

    /** Manager avec timeclock.* uniquement sur le store $storeId. */
    private function requestAsStoreManager(int $userId, int $storeId): Request
    {
        kintai_test_assignments(findByUser: fn($a0) => $a0 === $userId ? [
            ['id' => 1, 'user_id' => $userId, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => $storeId],
        ] : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 20 ? ['id' => 20, 'is_system' => 0] : null, getPermissions: fn($a0) => $a0 === 20 ? ['timeclock.view', 'timeclock.update', 'timeclock.delete'] : []);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        return $req;
    }

    public function testIndexReturnsEmptyWithoutFilters(): void
    {
        $response = $this->controller()->index($this->requestAsOwner());

        $body = json_decode($response->getContent(), true);
        $this->assertSame([], $body['data']);
    }

    public function testIndexFiltersByStoreAndDate(): void
    {
        $this->resetRows();
        $_GET = ['store_id' => '5', 'date' => '2026-08-01'];

        $this->seedRows([['id' => 1, 'store_id' => 5, 'shift_date' => '2026-08-01']]);

        $response = $this->controller()->index($this->requestAsOwner());
        $body = json_decode($response->getContent(), true);

        $this->assertCount(1, $body['data']);
    }

    public function testIndexByUserIdExcludesEntriesFromOtherStoresForScopedManager(): void
    {
        $this->resetRows();
        $_GET = ['user_id' => '99'];

        $this->seedRows([
            ['id' => 1, 'store_id' => 5, 'user_id' => 99],
            ['id' => 2, 'store_id' => 9, 'user_id' => 99],
        ]);

        $response = $this->controller()->index($this->requestAsStoreManager(2, 5));
        $body = json_decode($response->getContent(), true);

        $this->assertCount(1, $body['data']);
        $this->assertSame(5, $body['data'][0]['store_id']);
    }

    public function testClockInRejectsWhenAlreadyActive(): void
    {
        $this->resetRows();
        $req = $this->requestWithJson(['user_id' => 1]);

        $this->seedRows([['id' => 5, 'user_id' => 1, 'clock_out_time' => null]]);
        $this->expectNoChange();

        $this->expectException(ConflictException::class);
        $this->controller()->clockIn($req);
    }

    public function testClockOutThrowsWhenNoActiveEntry(): void
    {
        $this->resetRows();
        $req = $this->requestWithJson(['user_id' => 1]);

        $this->seedRows([]);

        $this->expectException(NotFoundException::class);
        $this->controller()->clockOut($req);
    }

    /**
     * Simule un corps JSON via réflexion : lire php://input n'est pas possible
     * dans un test unitaire (cf. tests/Unit/Controller/Api/V1/AuthControllerTest.php).
     */
    private function requestWithJson(array $json): Request
    {
        $req = Request::capture();
        kintai_test_set_json($req, $json);
        return $req;
    }

    public function testDestroyDeletesExistingEntry(): void
    {
        $this->resetRows();
        $this->seedRows([['id' => 7, 'store_id' => 5]]);
        $this->expectDeleted(7);

        $req = $this->requestAsOwner();
        $req->setRouteParams(['id' => '7']);

        $response = $this->controller()->destroy($req);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDestroyRejectsEntryFromAnotherStore(): void
    {
        $this->resetRows();
        $this->seedRows([['id' => 7, 'store_id' => 9]]);
        $this->expectNoChange();

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '7']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->destroy($req);
    }

    public function testUpdateRejectsEntryFromAnotherStore(): void
    {
        $this->resetRows();
        $this->seedRows([['id' => 7, 'store_id' => 9]]);
        $this->expectNoChange();

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '7']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->update($req);
    }
}
