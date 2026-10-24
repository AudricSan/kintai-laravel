<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use kintai\UI\Controller\Api\V1\StoreUserController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\ForbiddenException;
use PHPUnit\Framework\TestCase;

final class StoreUserControllerTest extends TestCase
{
    private StoreUserRepositoryInterface&MockObject $storeUsers;
    private StoreRepositoryInterface&Stub $stores;
    private UserRepositoryInterface&Stub $users;
    private StoreUserController $controller;

    protected function setUp(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->stores     = $this->createStub(StoreRepositoryInterface::class);
        $this->users      = $this->createStub(UserRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 3]);
        $this->controller = new StoreUserController($this->storeUsers, $this->stores, $this->users, new AuditLogger());
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    private function request(array $body, array $params = ['store_id' => '3']): Request
    {
        $req = new Request();
        $json = new \ReflectionProperty(Request::class, 'jsonBody');
        $json->setAccessible(true);
        $json->setValue($req, $body);
        $par = new \ReflectionProperty(Request::class, 'routeParams');
        $par->setAccessible(true);
        $par->setValue($req, $params);
        return $req;
    }

    /** Un `id`, un `role` ou un `store_id` postés ne doivent jamais atteindre le repository. */
    public function testStoreIgnoresIdRoleAndForeignStoreId(): void
    {
        $this->users->method('findById')->willReturn(['id' => 9]);
        $this->storeUsers->method('findMembership')->willReturn(null);
        $this->storeUsers->expects($this->once())->method('save')
            ->with(['user_id' => 9, 'staff_code' => 'A1', 'store_id' => 3])
            ->willReturn(['id' => 50]);

        $res = $this->controller->store($this->request([
            'id' => 7, 'store_id' => 99, 'user_id' => 9, 'staff_code' => 'A1', 'role' => 'manager', 'is_manager' => 1,
        ]));
        $this->assertSame(201, $res->status());
    }

    public function testStoreRejectsUnknownUser(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->storeUsers->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller->store($this->request(['user_id' => 12345]));
    }

    public function testStoreRejectsDuplicateMembership(): void
    {
        $this->users->method('findById')->willReturn(['id' => 9]);
        $this->storeUsers->method('findMembership')->willReturn(['id' => 1]);
        $this->storeUsers->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller->store($this->request(['user_id' => 9]));
    }

    /** `user_id` est immuable en modification : on ne réaffecte pas une adhésion à quelqu'un d'autre. */
    public function testUpdateKeepsUserIdAndStoreId(): void
    {
        $this->storeUsers->method('findById')->willReturn(['id' => 5, 'store_id' => 3, 'user_id' => 9, 'staff_code' => 'A1']);
        $this->storeUsers->expects($this->once())->method('save')
            ->with(['id' => 5, 'store_id' => 3, 'user_id' => 9, 'staff_code' => 'B2'])
            ->willReturn(['id' => 5]);

        $this->controller->update($this->request(
            ['user_id' => 77, 'store_id' => 99, 'id' => 1, 'staff_code' => 'B2'],
            ['store_id' => '3', 'id' => '5'],
        ));
    }

    // -------------------------------------------------------------------------
    // Sa propre adhésion (audit du 13/10/2026, M13)
    // -------------------------------------------------------------------------

    private function actingAs(int $userId, Request $req): Request
    {
        $req->setAttribute('auth_user', ['id' => $userId]);
        return $req;
    }

    public function testACallerCannotDeleteTheirOwnMembership(): void
    {
        $this->stores->method('findById')->willReturn(['id' => 3]);
        $this->storeUsers->method('findById')->willReturn(['id' => 9, 'store_id' => 3, 'user_id' => 2]);
        $this->storeUsers->expects($this->never())->method('delete');

        $this->expectException(ForbiddenException::class);
        $this->controller->destroy($this->actingAs(2, $this->request([], ['store_id' => '3', 'id' => '9'])));
    }

    public function testACallerCannotDeactivateTheirOwnMembership(): void
    {
        $this->stores->method('findById')->willReturn(['id' => 3]);
        $this->storeUsers->method('findById')->willReturn(['id' => 9, 'store_id' => 3, 'user_id' => 2, 'is_active' => 1]);
        $this->storeUsers->expects($this->never())->method('save');

        $this->expectException(ForbiddenException::class);
        $this->controller->update($this->actingAs(2, $this->request(['is_active' => 0], ['store_id' => '3', 'id' => '9'])));
    }

    public function testAnotherMembersMembershipCanStillBeDeleted(): void
    {
        $this->stores->method('findById')->willReturn(['id' => 3]);
        $this->storeUsers->method('findById')->willReturn(['id' => 9, 'store_id' => 3, 'user_id' => 5]);
        $this->storeUsers->expects($this->once())->method('delete')->with(9);

        $response = $this->controller->destroy($this->actingAs(2, $this->request([], ['store_id' => '3', 'id' => '9'])));

        $this->assertSame(204, $response->status());
    }
}
