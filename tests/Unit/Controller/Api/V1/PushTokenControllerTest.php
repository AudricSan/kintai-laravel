<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use App\Models\StoreUser;
use App\Auth\UserTargetGuard;
use Tests\Support\ApiOwnerAuth;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DevicePushToken;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\PushTokenController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class PushTokenControllerTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private ?PushTokenController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(20);
        $this->users  = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): PushTokenController
    {
        if ($this->controller === null) {
            kintai_test_store_users(findByUser: fn() => []);
            $this->controller = new PushTokenController(new UserTargetGuard($this->users, ApiOwnerAuth::permissions($this)));
        }

        return $this->controller;
    }

    public function testStoreRegistersDeviceToken(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(20)->willReturn(['id' => 20]);

        $req = $this->requestWithJson(['token' => 'device-abc', 'platform' => 'android'], ['user_id' => '20']);
        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->getStatusCode());
        $saved = DevicePushToken::first();
        $this->assertSame(20, (int) $saved->user_id);
        $this->assertSame('device-abc', $saved->token);
        $this->assertSame('android', $saved->platform);
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
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(20)->willReturn(['id' => 20]);
        DevicePushToken::put(['user_id' => 20, 'token' => 'device-abc', 'platform' => 'android']);

        $req = $this->requestWithJson(['token' => 'device-abc'], ['user_id' => '20']);
        $response = $this->controller()->destroy($req);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, DevicePushToken::count());
    }

    private function requestWithJson(array $json, array $routeParams = []): Request
    {
        $req = ApiOwnerAuth::owner(Request::capture());
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($json));
        $req->setRouteParams($routeParams);
        return $req;
    }
}
