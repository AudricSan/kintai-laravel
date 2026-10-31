<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff\Controllers\Api;


use App\Models\StoreUser;
use kintai\Bundles\Installed\TimeOff\Controllers\Api\TimeoffRequestController;
use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\RoleAssignment;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Régression (audit RBAC du 11/09/2026) : show/update/destroy ne re-vérifiaient
 * jamais que la demande de congé chargée appartenait à un store géré par
 * l'appelant — voir le docblock de TimeoffRequestController.
 */
final class TimeoffRequestControllerTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeoff_requests', 'timeoff'];
    }

    private TimeoffRequestController $controller;

    protected function setUp(): void
    {
        $this->resetRows();
        $this->controller      = new TimeoffRequestController(
            new AuditLogger(),
            new PermissionService()
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    private function requestAsStoreManager(int $userId, int $storeId): Request
    {
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => $userId, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => $storeId],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 20 ? ['id' => 20, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 20 ? ['timeoff.view', 'timeoff.update', 'timeoff.delete'] : []);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        return $req;
    }

    public function testIndexByUserIdExcludesRequestsFromOtherStoresForScopedManager(): void
    {
        $_GET = ['user_id' => '99'];

        $this->seedRows([
            ['id' => 1, 'store_id' => 5, 'user_id' => 99],
            ['id' => 2, 'store_id' => 9, 'user_id' => 99],
        ]);

        $response = $this->controller->index($this->requestAsStoreManager(2, 5));
        $body = json_decode($response->getContent(), true);

        $this->assertCount(1, $body['data']);
        $this->assertSame(5, $body['data'][0]['store_id']);
    }

    public function testShowThrowsNotFoundForMissingRequest(): void
    {
        $this->seedRows([]);

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '99']);

        $this->expectException(NotFoundException::class);
        $this->controller->show($req);
    }

    public function testShowRejectsRequestFromAnotherStore(): void
    {
        $this->seedRows([['id' => 5, 'store_id' => 9]]);

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller->show($req);
    }

    public function testUpdateRejectsRequestFromAnotherStore(): void
    {
        $this->seedRows([['id' => 5, 'store_id' => 9]]);
        $this->expectNoChange();

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller->update($req);
    }

    public function testDestroyAllowsRequestInManagedStore(): void
    {
        $this->seedRows([['id' => 5, 'store_id' => 5]]);
        $this->expectDeleted(5);

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller->destroy($req);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDestroyRejectsRequestFromAnotherStore(): void
    {
        $this->seedRows([['id' => 5, 'store_id' => 9]]);
        $this->expectNoChange();

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller->destroy($req);
    }
}
