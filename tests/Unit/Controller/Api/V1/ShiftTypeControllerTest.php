<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use Tests\Support\ApiOwnerAuth;
use App\Auth\PermissionService;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Models\Role;
use App\Models\ShiftType;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\V1\ShiftTypeController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ShiftTypeControllerTest extends TestCase
{
    private ?ShiftTypeController $controller = null;

    protected function setUp(): void
    {
        kintai_test_shift_types([]);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): ShiftTypeController
    {
        if ($this->controller === null) {
            $this->controller = new ShiftTypeController(new AuditLogger(), ApiOwnerAuth::permissions($this));
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
    }

    private function makeJsonRequest(array $body): Request
    {
        $req = ApiOwnerAuth::owner(Request::capture());
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
        return $req;
    }

    public function testShowIncludesStoreIds(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1, 2]);

        $req = ApiOwnerAuth::owner(Request::capture());
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->show($req);

        $data = json_decode($response->getContent(), true);
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
        kintai_test_roles(findById: fn() => ['id' => 20, 'is_system' => 0], getPermissions: fn() => ['shifts.update'], getGlobalPermissionKeys: fn() => []);
        return new ShiftTypeController(new AuditLogger(), new PermissionService($assignments));
    }

    private function asManager(Request $request): Request
    {
        $request->attributes->set('auth_user', ['id' => 9]);
        return $request;
    }

    public function testSharedShiftTypeCannotBeDeletedByTheManagerOfOnlyOneOfItsStores(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1, 2]);
        $req = $this->asManager(Request::capture());
        $req->setRouteParams(['id' => '5']);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->managerOfStoreOne()->destroy($req);
    }

    public function testSharedShiftTypeCannotBeUpdatedByTheManagerOfOnlyOneOfItsStores(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1, 2]);
        $req = $this->asManager(Request::capture());
        $req->setRouteParams(['id' => '5']);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->managerOfStoreOne()->update($req);
    }

    public function testShiftTypeOfASingleStoreCanBeDeletedByItsManager(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1]);
        $req = $this->asManager(Request::capture());
        $req->setRouteParams(['id' => '5']);

        $this->assertSame(204, $this->managerOfStoreOne()->destroy($req)->getStatusCode());
        $this->assertNull(ShiftType::findById(5));
    }

    public function testStoreWithStoreIdsArraySyncsAllStores(): void
    {
        $req = $this->makeJsonRequest(['code' => 'MORNING', 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'store_ids' => [1, 2]]);

        kintai_test_shift_type_stores(null, [1, 2]);

        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->getStatusCode());
        $id = (int) json_decode($response->getContent(), true)['id'];
        $this->assertSame([1, 2], ShiftType::getStoreIds($id));
    }

    /** store_id scalaire (rétrocompatibilité) : toujours accepté, converti en tableau à 1 élément. */
    public function testStoreWithLegacyScalarStoreId(): void
    {
        $req = $this->makeJsonRequest(['code' => 'MORNING', 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'store_id' => 3]);

        kintai_test_shift_type_stores(null, [3]);

        $response = $this->controller()->store($req);

        $this->assertSame([3], ShiftType::getStoreIds((int) json_decode($response->getContent(), true)['id']));
    }

    public function testStoreDoesNotPassStoreIdKeyToRepositorySave(): void
    {
        $req = $this->makeJsonRequest(['code' => 'MORNING', 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'store_id' => 3]);

        kintai_test_shift_type_stores(null, [3]);

        // Les clés store_id / store_ids ne sont pas des colonnes : l'enregistrement échouerait si elles étaient transmises.
        $this->assertSame(201, $this->controller()->store($req)->getStatusCode());
    }

    /** Mise à jour partielle : store_ids absent du corps ne touche pas aux affectations existantes. */
    public function testUpdateWithoutStoreIdsLeavesAssignmentsUntouched(): void
    {
        kintai_test_shift_types([['id' => 9, 'code' => 'MORNING', 'store_ids' => [1]]]);
        $req = $this->makeJsonRequest(['name' => 'Matin renommé']);
        $req->setRouteParams(['id' => '9']);

        $response = $this->controller()->update($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Matin renommé', ShiftType::findById(9)['name']);
        $this->assertSame([1], ShiftType::getStoreIds(9));
    }

    public function testUpdateWithStoreIdsReplacesAssignments(): void
    {
        kintai_test_shift_types([['id' => 9, 'code' => 'MORNING', 'store_ids' => [1]]]);
        $req = $this->makeJsonRequest(['store_ids' => [4, 5]]);
        $req->setRouteParams(['id' => '9']);
        kintai_test_shift_type_stores(null, [4, 5]);
        kintai_test_shift_type_stores(9, [1]);

        $this->controller()->update($req);

        $this->assertSame([4, 5], ShiftType::getStoreIds(9));
    }

    /** Audit M1 : une couleur qui n'est pas un hexadécimal est refusée (422) avant toute écriture. */
    public function testStoreRejectsAnInvalidColor(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeJsonRequest(['code' => 'X', 'name' => 'X', 'color' => 'red;position:fixed', 'store_ids' => [1]]));
    }

    public function testUpdateRejectsAnInvalidColor(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'X']]);
        kintai_test_shift_type_stores(null, [1]);
        $req = $this->makeJsonRequest(['color' => '"><script>']);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ValidationException::class);
        $this->controller()->update($req);
    }

    public function testStoreAcceptsAHexColor(): void
    {
        kintai_test_shift_type_stores(null, [1]);

        $response = $this->controller()->store($this->makeJsonRequest(['code' => 'X', 'name' => 'X', 'start_time' => '08:00', 'end_time' => '16:00', 'color' => '#a1b2c3', 'store_ids' => [1]]));

        $this->assertSame(201, $response->getStatusCode());
    }
}
