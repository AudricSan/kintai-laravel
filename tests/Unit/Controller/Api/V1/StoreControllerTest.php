<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Tests\Support\ApiOwnerAuth;
use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use kintai\Core\Services\StoreServiceInterface;
use kintai\UI\Controller\Api\V1\StoreController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class StoreControllerTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private StoreServiceInterface&Stub $storeService;
    private ?StoreController $controller = null;

    protected function setUp(): void
    {
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->storeService = $this->createStub(StoreServiceInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): StoreController
    {
        if ($this->controller === null) {
            $this->controller = new StoreController($this->stores, $this->storeService, new AuditLogger(), ApiOwnerAuth::permissions($this));
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    private function makeJsonRequest(array $body): Request
    {
        $req = ApiOwnerAuth::owner(new Request());
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setAccessible(true);
        $ref->setValue($req, $body);
        return $req;
    }

    /** POST /api/v1/stores délègue à StoreService::createStore() (et non plus au repository directement), pour que les règles métier (validation, quota du plan) s'appliquent aussi côté API. */
    public function testStoreDelegatesToStoreService(): void
    {
        $this->storeService = $this->createMock(StoreServiceInterface::class);
        $this->storeService->expects($this->once())
            ->method('createStore')
            ->with(['code' => 'ST01', 'name' => 'Store A'])
            ->willReturn(['id' => 1, 'code' => 'ST01']);

        $response = $this->controller()->store($this->makeJsonRequest(['code' => 'ST01', 'name' => 'Store A']));

        $this->assertSame(201, $response->status());
    }

    public function testStorePropagatesPlanLimitException(): void
    {
        $this->storeService->method('createStore')->willThrowException(new PlanLimitExceededException('plan_limit_stores'));

        $this->expectException(PlanLimitExceededException::class);
        $this->controller()->store($this->makeJsonRequest(['code' => 'ST01', 'name' => 'Store A']));
    }

    // -------------------------------------------------------------------------
    // Validation de la modification (audit du 13/10/2026, M9)
    // -------------------------------------------------------------------------

    private function updateRequest(array $body): Request
    {
        $req = $this->makeJsonRequest($body);
        $req->setRouteParams(['id' => '5']);
        return $req;
    }

    /** PUT /api/v1/stores/{id} avec {} était accepté (200 sans rien changer). */
    public function testUpdateWithAnEmptyBodyIsRefused(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 5, 'code' => 'TKY']);
        $this->stores->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->update($this->updateRequest([]));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidStoreUpdates(): array
    {
        return [
            'nom vide'            => [['name' => '']],
            'fuseau inconnu'      => [['timezone' => 'Mars/Olympus']],
            'type inconnu'        => [['type' => 'casino']],
            'jour de semaine'     => [['week_start_day' => 9]],
            'heure creuse'        => [['low_staff_hour_start' => 30]],
            'code mal forme'      => [['code' => 'a b']],
            'email invalide'      => [['email' => 'pas-un-email']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStoreUpdates')]
    public function testUpdateRefusesInvalidValues(array $body): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 5, 'code' => 'TKY']);
        $this->stores->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->update($this->updateRequest($body));
    }

    public function testUpdateRefusesACodeUsedByAnotherStore(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 5, 'code' => 'TKY']);
        $this->stores->expects($this->atLeastOnce())->method('findByCode')->with('OSK')->willReturn(['id' => 6, 'code' => 'OSK']);
        $this->stores->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->update($this->updateRequest(['code' => 'osk']));
    }

    public function testUpdateWritesOnlyTheValidatedFields(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 5, 'code' => 'TKY']);
        $this->stores->expects($this->once())->method('save')
            ->with(['name' => 'Tokyo Est', 'is_active' => 0, 'id' => 5])
            ->willReturn(['id' => 5, 'name' => 'Tokyo Est']);

        $response = $this->controller()->update($this->updateRequest(['name' => ' Tokyo Est ', 'is_active' => false, 'created_at' => 'x', 'daily_report_settings' => '{}']));

        $this->assertSame(200, $response->status());
    }

    /** DELETE /api/v1/stores/{id} passe par StoreService (suppression douce + rôles retirés), comme le web. */
    public function testDestroyDelegatesToTheStoreService(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeService = $this->createMock(StoreServiceInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 5]);
        $this->stores->expects($this->never())->method('delete');
        $this->storeService->expects($this->once())->method('deleteStore')->with(5);

        $req = $this->makeJsonRequest([]);
        $req->setRouteParams(['id' => '5']);

        $this->assertSame(204, $this->controller()->destroy($req)->status());
    }
}
