<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use App\Exceptions\ValidationException;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\V1\StoreUserController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Exceptions\ForbiddenException;
use PHPUnit\Framework\TestCase;

final class StoreUserControllerTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private UserRepositoryInterface&Stub $users;
    private StoreUserController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
        $this->stores     = $this->createStub(StoreRepositoryInterface::class);
        $this->users      = $this->createStub(UserRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 3]);
        $this->controller = new StoreUserController($this->stores, $this->users, new AuditLogger());
    }

    protected function tearDown(): void
    {
    }

    private function request(array $body, array $params = ['store_id' => '3']): Request
    {
        $req = Request::capture();
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
        $req->setRouteParams($params);
        return $req;
    }

    /** Un `id`, un `role` ou un `store_id` postés ne doivent jamais atteindre le repository. */
    public function testStoreIgnoresIdRoleAndForeignStoreId(): void
    {
        $this->users->method('findById')->willReturn(['id' => 9]);
        kintai_test_store_users(findMembership: fn() => null);
        $res = $this->controller->store($this->request([
            'id' => 7, 'store_id' => 99, 'user_id' => 9, 'staff_code' => 'A1', 'role' => 'manager', 'is_manager' => 1,
        ]));
        $this->assertSame(201, $res->getStatusCode());
        $row = \App\Models\StoreUser::query()->where('user_id', 9)->first();
        $this->assertSame(3, (int) $row->store_id, 'le magasin vient de la route, pas du corps');
        $this->assertNotSame(7, (int) $row->id, 'l\'identifiant du corps est ignoré');
        $this->assertSame('A1', $row->staff_code);
    }

    public function testStoreRejectsUnknownUser(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->expectException(ValidationException::class);
        $this->controller->store($this->request(['user_id' => 12345]));
    }

    public function testStoreRejectsDuplicateMembership(): void
    {
        $this->users->method('findById')->willReturn(['id' => 9]);
        kintai_test_store_users(findMembership: fn() => ['id' => 1]);
        $this->expectException(ValidationException::class);
        $this->controller->store($this->request(['user_id' => 9]));
    }

    /** `user_id` est immuable en modification : on ne réaffecte pas une adhésion à quelqu'un d'autre. */
    public function testUpdateKeepsUserIdAndStoreId(): void
    {
        kintai_test_store_users(findById: fn() => ['id' => 5, 'store_id' => 3, 'user_id' => 9, 'staff_code' => 'A1']);
        $this->controller->update($this->request(
            ['user_id' => 77, 'store_id' => 99, 'id' => 1, 'staff_code' => 'B2'],
            ['store_id' => '3', 'id' => '5'],
        ));

        $row = \App\Models\StoreUser::findById(5);
        $this->assertSame(9, (int) $row['user_id']);
        $this->assertSame(3, (int) $row['store_id']);
        $this->assertSame('B2', $row['staff_code']);
    }

    // -------------------------------------------------------------------------
    // Sa propre adhésion (audit du 13/10/2026, M13)
    // -------------------------------------------------------------------------

    private function actingAs(int $userId, Request $req): Request
    {
        $req->attributes->set('auth_user', ['id' => $userId]);
        return $req;
    }

    public function testACallerCannotDeleteTheirOwnMembership(): void
    {
        $this->stores->method('findById')->willReturn(['id' => 3]);
        kintai_test_store_users(findById: fn() => ['id' => 9, 'store_id' => 3, 'user_id' => 2]);
        $this->expectException(ForbiddenException::class);
        $this->controller->destroy($this->actingAs(2, $this->request([], ['store_id' => '3', 'id' => '9'])));
    }

    public function testACallerCannotDeactivateTheirOwnMembership(): void
    {
        $this->stores->method('findById')->willReturn(['id' => 3]);
        kintai_test_store_users(findById: fn() => ['id' => 9, 'store_id' => 3, 'user_id' => 2, 'is_active' => 1]);
        $this->expectException(ForbiddenException::class);
        $this->controller->update($this->actingAs(2, $this->request(['is_active' => 0], ['store_id' => '3', 'id' => '9'])));
    }

    public function testAnotherMembersMembershipCanStillBeDeleted(): void
    {
        $this->stores->method('findById')->willReturn(['id' => 3]);
        kintai_test_store_users(findById: fn() => ['id' => 9, 'store_id' => 3, 'user_id' => 5]);
        $response = $this->controller->destroy($this->actingAs(2, $this->request([], ['store_id' => '3', 'id' => '9'])));

        $this->assertNull(\App\Models\StoreUser::findById(9), 'appartenance supprimée');
        $this->assertSame(204, $response->getStatusCode());
    }
}
