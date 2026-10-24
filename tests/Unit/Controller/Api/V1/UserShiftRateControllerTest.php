<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Auth\UserTargetGuard;
use kintai\Tests\Support\ApiOwnerAuth;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Api\V1\UserShiftRateController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class UserShiftRateControllerTest extends TestCase
{
    private UserShiftTypeRateRepositoryInterface&Stub $rates;
    private UserRepositoryInterface&Stub $users;
    private ?UserShiftRateController $controller = null;

    protected function setUp(): void
    {
        $this->rates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturnCallback(fn(int $id): ?array => $id === 20 ? ['id' => 20] : null);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): UserShiftRateController
    {
        if ($this->controller === null) {
            $this->controller = new UserShiftRateController($this->rates, $this->guard(), new AuditLogger());
        }

        return $this->controller;
    }

    private function guard(): UserTargetGuard
    {
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByUser')->willReturn([]);
        return new UserTargetGuard($this->users, $storeUsers, ApiOwnerAuth::permissions($this));
    }

    // -------------------------------------------------------------------------
    // index() / show()
    // -------------------------------------------------------------------------

    public function testIndexReturnsRatesForUser(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->atLeastOnce())->method('findByUser')->with(20)->willReturn([['id' => 1, 'user_id' => 20]]);

        $response = $this->controller()->index($this->requestFor(['user_id' => '20']));

        $this->assertSame(200, $response->status());
        $this->assertCount(1, json_decode($response->body(), true));
    }

    public function testIndexThrowsWhenUserNotFound(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(99)->willReturn(null);
        $controller = new UserShiftRateController($this->rates, $this->guard(), new AuditLogger());

        $this->expectException(NotFoundException::class);
        $controller->index($this->requestFor(['user_id' => '99']));
    }

    public function testShowThrowsWhenRateBelongsToAnotherUser(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'user_id' => 999]);

        $this->expectException(NotFoundException::class);
        $this->controller()->show($this->requestFor(['user_id' => '20', 'id' => '5']));
    }

    // -------------------------------------------------------------------------
    // store()
    // -------------------------------------------------------------------------

    public function testStoreSavesRateAndReturns201(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['user_id'] === 20 && $d['shift_type_id'] === 3 && $d['hourly_rate'] === 15.5
        ))->willReturn(['id' => 1, 'user_id' => 20, 'shift_type_id' => 3, 'hourly_rate' => 15.5]);

        $req = $this->requestFor(['user_id' => '20'], ['shift_type_id' => 3, 'hourly_rate' => 15.5]);
        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->status());
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdateSavesRateForExistingRecord(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'user_id' => 20, 'hourly_rate' => 10.0]);
        $this->rates->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['id'] === 5 && $d['hourly_rate'] === 20.0
        ))->willReturn(['id' => 5, 'user_id' => 20, 'hourly_rate' => 20.0]);

        $req = $this->requestFor(['user_id' => '20', 'id' => '5'], ['hourly_rate' => 20.0]);
        $response = $this->controller()->update($req);

        $this->assertSame(200, $response->status());
    }

    public function testUpdateThrowsWhenRateNotFound(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->requestFor(['user_id' => '20', 'id' => '5'], ['hourly_rate' => 20.0]));
    }

    // -------------------------------------------------------------------------
    // destroy()
    // -------------------------------------------------------------------------

    public function testDestroyDeletesRate(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'user_id' => 20]);
        $this->rates->expects($this->once())->method('delete')->with(5);

        $response = $this->controller()->destroy($this->requestFor(['user_id' => '20', 'id' => '5']));

        $this->assertSame(204, $response->status());
    }

    public function testDestroyThrowsWhenRateBelongsToAnotherUser(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'user_id' => 999]);

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy($this->requestFor(['user_id' => '20', 'id' => '5']));
    }

    private function requestFor(array $routeParams, array $json = []): Request
    {
        $req = ApiOwnerAuth::owner(new Request());
        if ($json !== []) {
            $ref = new \ReflectionProperty(Request::class, 'jsonBody');
            $ref->setAccessible(true);
            $ref->setValue($req, $json);
        }
        $req->setRouteParams($routeParams);
        return $req;
    }

    // -------------------------------------------------------------------------
    // Validation du corps (audit du 13/10/2026, M9)
    // -------------------------------------------------------------------------

    public function testStoreWithAnEmptyBodyIsRefused(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->requestFor(['user_id' => '20']));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRates(): array
    {
        return [
            'taux negatif'       => [['shift_type_id' => 3, 'hourly_rate' => -5]],
            'taux en texte'      => [['shift_type_id' => 3, 'hourly_rate' => 'beaucoup']],
            'type non numerique' => [['shift_type_id' => 'abc', 'hourly_rate' => 10]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRates')]
    public function testStoreRefusesInvalidValues(array $body): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->rates->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->requestFor(['user_id' => '20'], $body));
    }

    public function testStoreRefusesAnUnknownShiftType(): void
    {
        $this->rates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $shiftTypes->expects($this->atLeastOnce())->method('findById')->with(404)->willReturn(null);
        $controller = new UserShiftRateController($this->rates, $this->guard(), new AuditLogger(), $shiftTypes);
        $this->rates->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $controller->store($this->requestFor(['user_id' => '20'], ['shift_type_id' => 404, 'hourly_rate' => 12]));
    }
}
