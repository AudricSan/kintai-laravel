<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use App\Repositories\StoreUserRepositoryInterface;
use App\Auth\UserTargetGuard;
use Tests\Support\ApiOwnerAuth;
use App\Exceptions\NotFoundException;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Http\Controllers\Api\V1\UserShiftRateController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Exceptions\ValidationException;
use App\Repositories\ShiftTypeRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class UserShiftRateControllerTest extends TestCase
{
    use \Tests\Support\UserRateExpectations;

    private UserRepositoryInterface&Stub $users;
    private ?UserShiftRateController $controller = null;

    protected function setUp(): void
    {
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturnCallback(fn(int $id): ?array => $id === 20 ? ['id' => 20] : null);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): UserShiftRateController
    {
        if ($this->controller === null) {
            $this->controller = new UserShiftRateController($this->guard(), new AuditLogger());
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
        $this->seedRate(20, 1, 10.0);

        $response = $this->controller()->index($this->requestFor(['user_id' => '20']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, json_decode($response->getContent(), true));
    }

    public function testIndexThrowsWhenUserNotFound(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(99)->willReturn(null);
        $controller = new UserShiftRateController($this->guard(), new AuditLogger());

        $this->expectException(NotFoundException::class);
        $controller->index($this->requestFor(['user_id' => '99']));
    }

    public function testShowThrowsWhenRateBelongsToAnotherUser(): void
    {
        $this->seedRate(999, 1, 10.0, 5);

        $this->expectException(NotFoundException::class);
        $this->controller()->show($this->requestFor(['user_id' => '20', 'id' => '5']));
    }

    // -------------------------------------------------------------------------
    // store()
    // -------------------------------------------------------------------------

    public function testStoreSavesRateAndReturns201(): void
    {
        $this->prepareRateTarget(20, 3);
        $this->expectRate(20, 3, 15.5);

        $req = $this->requestFor(['user_id' => '20'], ['shift_type_id' => 3, 'hourly_rate' => 15.5]);
        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdateSavesRateForExistingRecord(): void
    {
        $this->seedRate(20, 3, 10.0, 5);
        $this->expectRate(20, 3, 20.0);

        $req = $this->requestFor(['user_id' => '20', 'id' => '5'], ['hourly_rate' => 20.0]);
        $response = $this->controller()->update($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateThrowsWhenRateNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->requestFor(['user_id' => '20', 'id' => '5'], ['hourly_rate' => 20.0]));
    }

    // -------------------------------------------------------------------------
    // destroy()
    // -------------------------------------------------------------------------

    public function testDestroyDeletesRate(): void
    {
        $this->seedRate(20, 3, 10.0, 5);
        $this->expectRateRemoved(5);

        $response = $this->controller()->destroy($this->requestFor(['user_id' => '20', 'id' => '5']));

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDestroyThrowsWhenRateBelongsToAnotherUser(): void
    {
        $this->seedRate(999, 1, 10.0, 5);

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy($this->requestFor(['user_id' => '20', 'id' => '5']));
    }

    private function requestFor(array $routeParams, array $json = []): Request
    {
        $req = ApiOwnerAuth::owner(Request::capture());
        if ($json !== []) {
            $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($json));
        }
        $req->setRouteParams($routeParams);
        return $req;
    }

    // -------------------------------------------------------------------------
    // Validation du corps (audit du 13/10/2026, M9)
    // -------------------------------------------------------------------------

    public function testStoreWithAnEmptyBodyIsRefused(): void
    {
        $this->expectNoRateWrite();

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
        $this->expectNoRateWrite();

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->requestFor(['user_id' => '20'], $body));
    }

    public function testStoreRefusesAnUnknownShiftType(): void
    {
        $shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $shiftTypes->expects($this->atLeastOnce())->method('findById')->with(404)->willReturn(null);
        $controller = new UserShiftRateController($this->guard(), new AuditLogger(), $shiftTypes);
        $this->expectNoRateWrite();

        $this->expectException(ValidationException::class);
        $controller->store($this->requestFor(['user_id' => '20'], ['shift_type_id' => 404, 'hourly_rate' => 12]));
    }
}
