<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web\Scheduling;

use App\Models\ShiftType;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Scheduling\AdminShiftTypeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class AdminShiftTypeControllerTest extends TestCase
{
    private ?AdminShiftTypeController $controller = null;

    protected function setUp(): void
    {
        // Base neuve à chaque test : les types semés par un test ne doivent pas apparaître dans le suivant.
        kintai_test_database();
        kintai_test_migrate();
        $this->ensureViewFile('scheduling.shift-types');
        $this->ensureViewFile('scheduling.shift-types-form');
        $this->ensureViewFile('layout.app');
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminShiftTypeController
    {
        if ($this->controller === null) {
            $view = kintai_test_views(sys_get_temp_dir());
            $this->controller = new AdminShiftTypeController(
                $view,
                new AuditLogger(),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];
    }

    // -------------------------------------------------------------------------
    // storeShiftType — création
    // -------------------------------------------------------------------------

    public function testStoreShiftTypeRejectsEmptyStoreSelection(): void
    {
        $_POST = ['code' => 'MORNING', 'name' => 'Matin'];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->storeShiftType($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreShiftTypeSyncsSelectedStores(): void
    {
        $_POST = ['code' => 'morning', 'name' => 'Matin', 'store_ids' => ['1', '2']];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', null);

        kintai_test_shift_type_stores(null, [1, 2]);

        $response = $this->controller()->storeShiftType($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([1, 2], \App\Models\ShiftType::getStoreIds((int) \App\Models\ShiftType::query()->where('code', 'MORNING')->value('id')));
    }

    /** Un manager ne gérant aucun des stores cochés ne peut pas créer le type. */
    public function testStoreShiftTypeForbiddenWhenNoManagedStoreSelected(): void
    {
        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['99']];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', [1, 2]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->storeShiftType($req);
    }

    // -------------------------------------------------------------------------
    // updateShiftType — édition
    // -------------------------------------------------------------------------

    public function testUpdateShiftTypeRejectsEmptyStoreSelection(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']]);
        kintai_test_shift_type_stores(5, [1]);

        $_POST = ['code' => 'MORNING', 'name' => 'Matin'];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->updateShiftType($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * Un type de shift partagé touche les shifts et la paie de tous ses magasins : un gérant qui n'en gère qu'un
     * (ici le 1 sur [1, 2]) ne peut ni le modifier, ni le supprimer.
     */
    public function testUpdateOfASharedShiftTypeIsForbiddenToAManagerOfOnlyOneOfItsStores(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']]);
        kintai_test_shift_type_stores(5, [1, 2]);
        $req = $this->makePostRequest(['code' => 'MORNING', 'name' => 'Renommé', 'store_ids' => ['1']]);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->updateShiftType($req);
    }

    public function testUpdateOfASharedShiftTypeIsAllowedWhenManagingEveryStoreOfIt(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']]);
        kintai_test_shift_type_stores(5, [1, 2]);
        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
        $req = $this->makePostRequest(['code' => 'MORNING', 'name' => 'Renommé', 'store_ids' => ['1', '2']]);
        $req->attributes->set('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->updateShiftType($req)->getStatusCode());
        $this->assertSame('Renommé', \App\Models\ShiftType::findById(5)['name']);
    }

    public function testUpdateShiftTypeForbiddenWhenManagingNoneOfExistingStores(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']]);
        kintai_test_shift_type_stores(5, [2]);

        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['2']];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->updateShiftType($req);
    }

    // -------------------------------------------------------------------------
    // deleteShiftType
    // -------------------------------------------------------------------------

    public function testDeleteShiftTypeForbiddenWhenNotManagingAnyOfItsStores(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [2]);

        $req = $this->makePostRequest([]);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->deleteShiftType($req);
    }

    public function testDeleteOfASharedShiftTypeIsForbiddenToAManagerOfOnlyOneOfItsStores(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1, 2]);
        $req = $this->makePostRequest([]);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->deleteShiftType($req);
    }

    public function testDeleteOfAShiftTypeUsedByASingleStoreIsAllowedToItsManager(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1]);
        $req = $this->makePostRequest([]);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->deleteShiftType($req)->getStatusCode());
        $this->assertNull(\App\Models\ShiftType::findById(5));
    }

    public function testDeleteOfASharedShiftTypeIsAllowedWithoutStoreRestriction(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1, 2]);
        $req = $this->makePostRequest([]);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->deleteShiftType($req)->getStatusCode());
        $this->assertNull(\App\Models\ShiftType::findById(5));
    }

    // -------------------------------------------------------------------------
    // toggleShiftTypeStore — matrice magasin×type
    // -------------------------------------------------------------------------

    public function testToggleShiftTypeStoreNotFound(): void
    {
        kintai_test_shift_types([]);

        $req = $this->makePostRequest(['store_id' => '1', 'enabled' => '1']);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\App\Exceptions\NotFoundException::class);
        $this->controller()->toggleShiftTypeStore($req);
    }

    public function testToggleShiftTypeStoreForbiddenWhenStoreNotManaged(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'store_ids' => [1]]]);
        \Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => 2, 'name' => 'Magasin 2', 'code' => 'M2']);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '1']);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->toggleShiftTypeStore($req);
    }

    public function testToggleShiftTypeStoreEnablesWhenChecked(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'store_ids' => [1]]]);
        \Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => 2, 'name' => 'Magasin 2', 'code' => 'M2']);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '1']);
        $req->attributes->set('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->toggleShiftTypeStore($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue(\App\Models\ShiftType::isEnabledForStore(5, 2));
    }

    public function testToggleShiftTypeStoreDisablesWhenUnchecked(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [1, 2]);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '0']);
        $req->attributes->set('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->toggleShiftTypeStore($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(\App\Models\ShiftType::isEnabledForStore(5, 2));
    }

    /** Décocher le dernier store affecté doit être refusé, pas silencieusement orpheliner le type. */
    public function testToggleShiftTypeStoreRejectsDisablingLastStore(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING']]);
        kintai_test_shift_type_stores(5, [2]);

        $req = $this->makePostRequest(['store_id' => '2', 'enabled' => '0']);
        $req->attributes->set('managed_store_ids', [1, 2]);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->toggleShiftTypeStore($req);

        $this->assertSame(302, $response->getStatusCode());
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
            kintai_test_shift_types([
                ['id' => 1, 'name' => 'Partagé', 'store_ids' => [1, 2]], ['id' => 2, 'name' => 'Le mien', 'store_ids' => [1]],
            ]);
            kintai_test_stores(findAll: fn() => []);
            $req = Request::capture();
            $req->attributes->set('managed_store_ids', [1]);

            $this->assertSame('[1]', $this->controller()->shiftTypes($req)->getContent());
        } finally {
            file_put_contents($view, '');
            file_put_contents($layout, '');
        }
    }

    private function makePostRequest(array $post): Request
    {
        $_POST = $post;
        return Request::capture();
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
        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['1'], 'color' => 'red;position:fixed'];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', null);

        $this->expectException(ValidationException::class);
        $this->controller()->storeShiftType($req);
    }

    public function testUpdateShiftTypeRejectsAnInvalidColor(): void
    {
        kintai_test_shift_types([['id' => 5, 'code' => 'MORNING', 'name' => 'Matin']]);
        kintai_test_shift_type_stores(5, [1]);

        $_POST = ['code' => 'MORNING', 'name' => 'Matin', 'store_ids' => ['1'], 'color' => '"><b>'];
        $req = $this->makePostRequest($_POST);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 5]);

        $this->expectException(ValidationException::class);
        $this->controller()->updateShiftType($req);
    }
}
