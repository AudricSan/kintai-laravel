<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\Scheduling;

use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use kintai\UI\Controller\Web\Scheduling\AdminShiftTypeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class AdminShiftTypeControllerTest extends TestCase
{
    private ShiftTypeRepositoryInterface&Stub $shiftTypes;
    private StoreRepositoryInterface&Stub $stores;
    private ?AdminShiftTypeController $controller = null;

    protected function setUp(): void
    {
        $this->ensureViewFile('scheduling.shift-types');
        $this->ensureViewFile('scheduling.shift-types-form');
        $this->ensureViewFile('layout.app');

        $this->shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $this->stores      = $this->createStub(StoreRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminShiftTypeController
    {
        if ($this->controller === null) {
            $view = new ViewRenderer(sys_get_temp_dir());
            $this->controller = new AdminShiftTypeController(
                $view,
                $this->shiftTypes,
                $this->stores,
                new AuditLogger(),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];
        Log::reset();
    }

    // -------------------------------------------------------------------------
    // storeShiftType — création
    // -------------------------------------------------------------------------

    public function testStoreShiftTypeRejectsEmptyStoreSelection(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $_POST = ['code' => 'MORNING', 'name' => 'Matin'];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', null);

        $this->shiftTypes->expects($this->never())->method('save');

        $response = $this->controller()->storeShiftType($req);

        $this->assertSame(302, $response->status());
    }

    public function testStoreShiftTypeSyncsSelectedStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $_POST = ['code' => 'morning', 'name' => 'Matin', 'store_ids' => ['1', '2']];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', null);

        $this->shiftTypes->method('save')->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->once())->method('syncStores')->with(5, [1, 2]);

        $response = $this->controller()->storeShiftType($req);

        $this->assertSame(302, $response->status());
    }

    /** Un manager ne gérant aucun des stores cochés ne peut pas créer le type. */
    public function testStoreShiftTypeForbiddenWhenNoManagedStoreSelected(): void
    {
        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['99']];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', [1, 2]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->storeShiftType($req);
    }

    // -------------------------------------------------------------------------
    // updateShiftType — édition
    // -------------------------------------------------------------------------

    public function testUpdateShiftTypeRejectsEmptyStoreSelection(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1]);

        $_POST = ['code' => 'MORNING', 'name' => 'Matin'];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->never())->method('syncStores');

        $response = $this->controller()->updateShiftType($req);

        $this->assertSame(302, $response->status());
    }

    /**
     * Un type de shift partagé touche les shifts et la paie de tous ses magasins : un gérant qui n'en gère qu'un
     * (ici le 1 sur [1, 2]) ne peut ni le modifier, ni le supprimer.
     */
    public function testUpdateOfASharedShiftTypeIsForbiddenToAManagerOfOnlyOneOfItsStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);
        $this->shiftTypes->expects($this->never())->method('save');
        $this->shiftTypes->expects($this->never())->method('syncStores');

        $req = $this->makePostRequest(['code' => 'MORNING', 'name' => 'Renommé', 'store_ids' => ['1']]);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->updateShiftType($req);
    }

    public function testUpdateOfASharedShiftTypeIsAllowedWhenManagingEveryStoreOfIt(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);
        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
        $this->shiftTypes->expects($this->once())->method('save');
        $this->shiftTypes->expects($this->once())->method('syncStores')->with(5, [1, 2]);

        $req = $this->makePostRequest(['code' => 'MORNING', 'name' => 'Renommé', 'store_ids' => ['1', '2']]);
        $req->setAttribute('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->updateShiftType($req)->status());
    }

    public function testUpdateShiftTypeForbiddenWhenManagingNoneOfExistingStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([2]);

        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['2']];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->updateShiftType($req);
    }

    // -------------------------------------------------------------------------
    // deleteShiftType
    // -------------------------------------------------------------------------

    public function testDeleteShiftTypeForbiddenWhenNotManagingAnyOfItsStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([2]);

        $req = $this->makePostRequest([]);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->never())->method('delete');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->deleteShiftType($req);
    }

    public function testDeleteOfASharedShiftTypeIsForbiddenToAManagerOfOnlyOneOfItsStores(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);
        $this->shiftTypes->expects($this->never())->method('delete');

        $req = $this->makePostRequest([]);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->deleteShiftType($req);
    }

    public function testDeleteOfAShiftTypeUsedByASingleStoreIsAllowedToItsManager(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1]);
        $this->shiftTypes->expects($this->once())->method('delete')->with(5);

        $req = $this->makePostRequest([]);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->deleteShiftType($req)->status());
    }

    public function testDeleteOfASharedShiftTypeIsAllowedWithoutStoreRestriction(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);
        $this->shiftTypes->expects($this->once())->method('delete')->with(5);

        $req = $this->makePostRequest([]);
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->deleteShiftType($req)->status());
    }

    // -------------------------------------------------------------------------
    // toggleShiftTypeStore — matrice magasin×type
    // -------------------------------------------------------------------------

    public function testToggleShiftTypeStoreNotFound(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(null);

        $req = $this->makePostRequest(['store_id' => '1', 'enabled' => '1']);
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\kintai\Core\Exceptions\NotFoundException::class);
        $this->controller()->toggleShiftTypeStore($req);
    }

    public function testToggleShiftTypeStoreForbiddenWhenStoreNotManaged(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '1']);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->never())->method('enableForStore');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->toggleShiftTypeStore($req);
    }

    public function testToggleShiftTypeStoreEnablesWhenChecked(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '1']);
        $req->setAttribute('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->once())->method('enableForStore')->with(5, 2);
        $this->shiftTypes->expects($this->never())->method('disableForStore');

        $response = $this->controller()->toggleShiftTypeStore($req);

        $this->assertSame(302, $response->status());
    }

    public function testToggleShiftTypeStoreDisablesWhenUnchecked(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1, 2]);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '0']);
        $req->setAttribute('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->once())->method('disableForStore')->with(5, 2);

        $response = $this->controller()->toggleShiftTypeStore($req);

        $this->assertSame(302, $response->status());
    }

    /** Décocher le dernier store affecté doit être refusé, pas silencieusement orpheliner le type. */
    public function testToggleShiftTypeStoreRejectsDisablingLastStore(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([2]);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '0']);
        $req->setAttribute('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->never())->method('disableForStore');

        $response = $this->controller()->toggleShiftTypeStore($req);

        $this->assertSame(302, $response->status());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Audit n°8 : la liste ne propose plus de modifier ou supprimer un type partagé avec un magasin non géré (403). */
    public function testSharedShiftTypesAreMarkedLockedForAManagerOfOnlyOneOfTheirStores(): void
    {
        $dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $view = $dir . 'scheduling' . DIRECTORY_SEPARATOR . 'shift-types.php';
        $layout = $dir . 'layout' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents($view, '<?= json_encode($locked_type_ids) ?>');
        file_put_contents($layout, '<?php echo $content ?? "";');
        try {
            $this->shiftTypes->method('findByStores')->willReturn([
                ['id' => 1, 'name' => 'Partagé'], ['id' => 2, 'name' => 'Le mien'],
            ]);
            $this->shiftTypes->method('getStoreIds')->willReturnMap([[1, [1, 2]], [2, [1]]]);
            $this->stores->method('findAll')->willReturn([]);
            $req = new Request();
            $req->setAttribute('managed_store_ids', [1]);

            $this->assertSame('[1]', $this->controller()->shiftTypes($req)->body());
        } finally {
            file_put_contents($view, '');
            file_put_contents($layout, '');
        }
    }

    private function makePostRequest(array $post): Request
    {
        $_POST = $post;
        return new Request();
    }

    private function ensureViewFile(string $view): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($file);
    }

    /** Audit M1 : couleur forgée refusée (le sélecteur de couleur du formulaire n'envoie que des hexadécimaux). */
    public function testStoreShiftTypeRejectsAnInvalidColor(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['1'], 'color' => 'red;position:fixed'];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', null);

        $this->shiftTypes->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->storeShiftType($req);
    }

    public function testUpdateShiftTypeRejectsAnInvalidColor(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(5)->willReturn([1]);

        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['1'], 'color' => '"><b>'];
        $req = $this->makePostRequest($_POST);
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->shiftTypes->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->updateShiftType($req);
    }
}
