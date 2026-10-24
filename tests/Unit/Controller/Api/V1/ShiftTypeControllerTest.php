<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Tests\Support\ApiOwnerAuth;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use kintai\UI\Controller\Api\V1\ShiftTypeController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ShiftTypeControllerTest extends TestCase
{
    private ShiftTypeRepositoryInterface&Stub $shiftTypes;
    private ?ShiftTypeController $controller = null;

    protected function setUp(): void
    {
        $this->shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): ShiftTypeController
    {
        if ($this->controller === null) {
            $this->controller = new ShiftTypeController($this->shiftTypes, new AuditLogger(), ApiOwnerAuth::permissions($this));
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

    public function testShowIncludesStoreIds(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);

        $req = ApiOwnerAuth::owner(new Request());
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->show($req);

        $data = json_decode($response->body(), true);
        $this->assertSame([1, 2], $data['store_ids']);
    }

    /** Gérant du magasin 1 seulement (rôle non système avec shifts.update). */
    private function managerOfStoreOne(): ShiftTypeController
    {
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn([
            ['id' => 1, 'user_id' => 9, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $assignments->method('findByScope')->willReturn([]);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 20, 'is_system' => 0]);
        $roles->method('getPermissions')->willReturn(['shifts.update']);
        $roles->method('getGlobalPermissionKeys')->willReturn([]);

        return new ShiftTypeController($this->shiftTypes, new AuditLogger(), new PermissionService($assignments, $roles));
    }

    private function asManager(Request $request): Request
    {
        $request->setAttribute('auth_user', ['id' => 9]);
        return $request;
    }

    public function testSharedShiftTypeCannotBeDeletedByTheManagerOfOnlyOneOfItsStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);
        $this->shiftTypes->expects($this->never())->method('delete');

        $req = $this->asManager(new Request());
        $req->setRouteParams(['id' => '5']);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->managerOfStoreOne()->destroy($req);
    }

    public function testSharedShiftTypeCannotBeUpdatedByTheManagerOfOnlyOneOfItsStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);
        $this->shiftTypes->expects($this->never())->method('save');

        $req = $this->asManager(new Request());
        $req->setRouteParams(['id' => '5']);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->managerOfStoreOne()->update($req);
    }

    public function testShiftTypeOfASingleStoreCanBeDeletedByItsManager(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1]);
        $this->shiftTypes->expects($this->once())->method('delete')->with(5);

        $req = $this->asManager(new Request());
        $req->setRouteParams(['id' => '5']);

        $this->assertSame(204, $this->managerOfStoreOne()->destroy($req)->status());
    }

    public function testStoreWithStoreIdsArraySyncsAllStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $req = $this->makeJsonRequest(['code' => 'MORNING', 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'store_ids' => [1, 2]]);

        $this->shiftTypes->method('save')->willReturn(['id' => 9]);
        $this->shiftTypes->expects($this->once())->method('syncStores')->with(9, [1, 2]);
        $this->shiftTypes->method('getStoreIds')->willReturn([1, 2]);

        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->status());
    }

    /** store_id scalaire (rétrocompatibilité) : toujours accepté, converti en tableau à 1 élément. */
    public function testStoreWithLegacyScalarStoreId(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $req = $this->makeJsonRequest(['code' => 'MORNING', 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'store_id' => 3]);

        $this->shiftTypes->method('save')->willReturn(['id' => 9]);
        $this->shiftTypes->expects($this->once())->method('syncStores')->with(9, [3]);
        $this->shiftTypes->method('getStoreIds')->willReturn([3]);

        $this->controller()->store($req);
    }

    public function testStoreDoesNotPassStoreIdKeyToRepositorySave(): void
    {
        $req = $this->makeJsonRequest(['code' => 'MORNING', 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'store_id' => 3]);

        $captured = null;
        $this->shiftTypes->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 9];
        });
        $this->shiftTypes->method('getStoreIds')->willReturn([3]);

        $this->controller()->store($req);

        $this->assertArrayNotHasKey('store_id', $captured);
        $this->assertArrayNotHasKey('store_ids', $captured);
    }

    /** Mise à jour partielle : store_ids absent du corps ne touche pas aux affectations existantes. */
    public function testUpdateWithoutStoreIdsLeavesAssignmentsUntouched(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn(['id' => 9, 'code' => 'MORNING']);
        $req = $this->makeJsonRequest(['name' => 'Matin renommé']);
        $req->setRouteParams(['id' => '9']);

        $this->shiftTypes->method('save')->willReturn(['id' => 9, 'name' => 'Matin renommé']);
        $this->shiftTypes->expects($this->never())->method('syncStores');
        $this->shiftTypes->method('getStoreIds')->willReturn([1]);

        $response = $this->controller()->update($req);

        $this->assertSame(200, $response->status());
    }

    public function testUpdateWithStoreIdsReplacesAssignments(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn(['id' => 9, 'code' => 'MORNING']);
        $req = $this->makeJsonRequest(['store_ids' => [4, 5]]);
        $req->setRouteParams(['id' => '9']);

        $this->shiftTypes->method('save')->willReturn(['id' => 9]);
        $this->shiftTypes->expects($this->once())->method('syncStores')->with(9, [4, 5]);
        $this->shiftTypes->method('getStoreIds')->willReturn([4, 5]);

        $this->controller()->update($req);
    }

    /** Audit M1 : une couleur qui n'est pas un hexadécimal est refusée (422) avant toute écriture. */
    public function testStoreRejectsAnInvalidColor(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeJsonRequest(['code' => 'X', 'name' => 'X', 'color' => 'red;position:fixed', 'store_ids' => [1]]));
    }

    public function testUpdateRejectsAnInvalidColor(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->method('findById')->willReturn(['id' => 5, 'code' => 'X']);
        $this->shiftTypes->method('getStoreIds')->willReturn([1]);
        $this->shiftTypes->expects($this->never())->method('save');

        $req = $this->makeJsonRequest(['color' => '"><script>']);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ValidationException::class);
        $this->controller()->update($req);
    }

    public function testStoreAcceptsAHexColor(): void
    {
        $this->shiftTypes->method('save')->willReturn(['id' => 9]);
        $this->shiftTypes->method('getStoreIds')->willReturn([1]);

        $response = $this->controller()->store($this->makeJsonRequest(['code' => 'X', 'name' => 'X', 'start_time' => '08:00', 'end_time' => '16:00', 'color' => '#a1b2c3', 'store_ids' => [1]]));

        $this->assertSame(201, $response->status());
    }
}
