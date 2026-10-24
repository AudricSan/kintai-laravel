<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Auth\UserTargetGuard;
use kintai\Tests\Support\ApiOwnerAuth;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\UI\Controller\Api\V1\PushTokenController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class PushTokenControllerTest extends TestCase
{
    private DevicePushTokenRepositoryInterface&Stub $tokens;
    private UserRepositoryInterface&Stub $users;
    private ?PushTokenController $controller = null;

    protected function setUp(): void
    {
        $this->tokens = $this->createStub(DevicePushTokenRepositoryInterface::class);
        $this->users  = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): PushTokenController
    {
        if ($this->controller === null) {
            $storeUsers   = $this->createStub(StoreUserRepositoryInterface::class);
            $storeUsers->method('findByUser')->willReturn([]);
            $this->controller = new PushTokenController($this->tokens, new UserTargetGuard($this->users, $storeUsers, ApiOwnerAuth::permissions($this)));
        }

        return $this->controller;
    }

    public function testStoreRegistersDeviceToken(): void
    {
        $this->tokens = $this->createMock(DevicePushTokenRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(20)->willReturn(['id' => 20]);
        $this->tokens->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['user_id'] === 20 && $d['token'] === 'device-abc' && $d['platform'] === 'android'
        ))->willReturn(['id' => 1, 'user_id' => 20, 'token' => 'device-abc', 'platform' => 'android']);

        $req = $this->requestWithJson(['token' => 'device-abc', 'platform' => 'android'], ['user_id' => '20']);
        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->status());
    }

    public function testStoreRejectsMissingToken(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(20)->willReturn(['id' => 20]);

        $req = $this->requestWithJson(['platform' => 'ios'], ['user_id' => '20']);

        $this->expectException(ValidationException::class);
        $this->controller()->store($req);
    }

    public function testStoreRejectsInvalidPlatform(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(20)->willReturn(['id' => 20]);

        $req = $this->requestWithJson(['token' => 'x', 'platform' => 'windows-phone'], ['user_id' => '20']);

        $this->expectException(ValidationException::class);
        $this->controller()->store($req);
    }

    public function testStoreThrowsWhenUserNotFound(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(99)->willReturn(null);

        $req = $this->requestWithJson(['token' => 'x'], ['user_id' => '99']);

        $this->expectException(NotFoundException::class);
        $this->controller()->store($req);
    }

    public function testDestroyUnregistersToken(): void
    {
        $this->tokens = $this->createMock(DevicePushTokenRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(20)->willReturn(['id' => 20]);
        $this->tokens->expects($this->once())->method('deleteByToken')->with('device-abc', 20);

        $req = $this->requestWithJson(['token' => 'device-abc'], ['user_id' => '20']);
        $response = $this->controller()->destroy($req);

        $this->assertSame(204, $response->status());
    }

    private function requestWithJson(array $json, array $routeParams = []): Request
    {
        $req = ApiOwnerAuth::owner(new Request());
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setAccessible(true);
        $ref->setValue($req, $json);
        $req->setRouteParams($routeParams);
        return $req;
    }
}
