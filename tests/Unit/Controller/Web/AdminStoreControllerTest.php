<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use Illuminate\Container\Container;
use Tests\Support\FakeBundleManagerFactory;
use App\Extension\StoreFeatures;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use App\Services\RoleAssignmentSyncService;
use App\Services\StoreServiceInterface;
use App\Services\StoreStatsServiceInterface;
use App\Http\Controllers\Staff\AdminStoreController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminStoreControllerTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private StoreStatsServiceInterface&Stub $storeStatsService;
    private ?AdminStoreController $controller = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $this->ensureViewFile('staff.stores');
        $this->ensureViewFile('staff.stores-form');
        $this->ensureViewFile('staff.employee-stats');
        $this->ensureViewFile('layout.app');
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->storeStatsService = $this->createStub(StoreStatsServiceInterface::class);
    }
    /** Audit n°8 : quota de magasins atteint → retour au formulaire (erreur de validation), plus une page 403. */
    public function testStoreQuotaReachedSendsBackToTheFormInsteadOfA403(): void
    {
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('createStore')->willThrowException(new \App\Exceptions\PlanLimitExceededException('1 magasin maximum'));
        $controller = new AdminStoreController(
            kintai_test_views(sys_get_temp_dir()),
            $this->users,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs([])),
            new RoleAssignmentSyncService(),
            new PermissionService(),
        );
        $_POST = ['code' => 'NEW', 'name' => 'Nouveau'];
        $req = Request::capture();
        $_POST = [];
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);

        try {
            $controller->storeStore($req);
            $this->fail('ValidationException attendue');
        } catch (\App\Exceptions\ValidationException $e) {
            $this->assertSame(['store' => '1 magasin maximum'], $e->errors);
        }
    }

    public function testStoresRendersList(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_stores(findAll: fn() => []);
        $response = $this->controller()->stores($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateStoreRendersForm(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $this->controller()->createStore($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditStoreRendersForm(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['store' => 1]);

        $store = [
            'id' => 1, 'name' => 'Test Store', 'currency' => 'JPY',
            'week_start_day' => 1, 'timezone' => 'Asia/Tokyo',
        ];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $store : null);
        kintai_test_store_features(1, ['shifts', 'timeclock']);
        kintai_test_store_users(findByStore: fn() => []);
        $this->users->method('findAll')->willReturn([]);

        $response = $this->controller()->editStore($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditStoreRendersFormWhenABundleIsDisabledInstanceWide(): void
    {
        $controller = new AdminStoreController(
            kintai_test_views(sys_get_temp_dir()),
            $this->users,
            new AuditLogger(),
            $this->createStub(StoreServiceInterface::class),
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['daily-report'])), // messaging et store-photos désactivés
            new RoleAssignmentSyncService(),
            new PermissionService(),
        );

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['store' => 1]);

        $store = ['id' => 1, 'name' => 'Test Store', 'currency' => 'JPY', 'week_start_day' => 1, 'timezone' => 'Asia/Tokyo'];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $store : null);
        kintai_test_store_features(1, ['shifts', 'timeclock', 'messages']);
        kintai_test_store_users(findByStore: fn() => []);
        $this->users->method('findAll')->willReturn([]);

        $response = $controller->editStore($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateStorePersistsPhotosFeatureSlug(): void
    {
        // Cases décochées : le champ caché envoie « 0 » (audit M12 : absent = inchangé).
        $_POST = ['name' => 'Test Store', 'feature_photos' => '1'] + array_fill_keys(
            ['feature_shifts', 'feature_timeclock', 'feature_timeoff', 'feature_swaps', 'feature_open_shifts', 'feature_messages', 'feature_daily_reports', 'feature_notes'],
            '0'
        );
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['store' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Test Store'] : null);
        // Magasin déjà configuré : décisions enregistrées pour plusieurs fonctionnalités.
        Store::saveFeatureDecisions(1, [
            'shifts' => true, 'timeclock' => true, 'photos' => true, 'messages' => false,
        ]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $controller = new AdminStoreController(
            kintai_test_views(sys_get_temp_dir()),
            $this->users,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            // Tous les bundles de fonctionnalités par-store activés, pour que ce test ne
            // couvre que la soumission normale d'une case cochée (voir
            // testUpdateStorePreservesFeatureHiddenByDisabledBundle pour le cas masqué).
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['messaging', 'daily-report', 'store-photos', 'timeoff', 'shift-swap', 'shift-claim', 'timeclock'])),
            new RoleAssignmentSyncService(),
            new PermissionService(),
        );

        $response = $controller->updateStore($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($captured);
        // Seule la fonctionnalité proposée (Core) reçoit une décision ; celles des bundles absents ne sont pas
        // envoyées et gardent leur décision enregistrée (saveFeatureDecisions ne touche qu'aux clés données).
        $this->assertSame(['shifts' => false], $captured['_features']);
    }

    public function testUpdateStorePreservesFeatureHiddenByDisabledBundle(): void
    {
        // "messages" et "photos" dépendent de bundles désactivés au niveau instance : leurs
        // cases ne sont pas rendues dans le formulaire, donc jamais soumises. Elles doivent
        // survivre à la sauvegarde au lieu d'être silencieusement décochées (régression
        // constatée en prod : éditer un store désactivait "photos" pour de bon).
        $_POST = ['name' => 'Test Store', 'feature_shifts' => '1'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['store' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Test Store'] : null);
        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $controller = new AdminStoreController(
            kintai_test_views(sys_get_temp_dir()),
            $this->users,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['daily-report'])), // messaging et store-photos désactivés
            new RoleAssignmentSyncService(),
            new PermissionService(),
        );

        $response = $controller->updateStore($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($captured);
        // Magasin jamais configuré : seule la case proposée est décidée ; messages et photos n'ont aucune décision et
        // restent donc actifs à la réactivation de leur bundle (régression du point 11 de la revue du 15/10/2026).
        $this->assertSame(['shifts' => true], $captured['_features']);
    }

    public function testEmployeeStatsLogsConsultation(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $log = kintai_test_log();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['store' => '1', 'employee' => '5']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Store A', 'currency' => 'JPY'] : null);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'last_name' => 'Dupont', 'first_name' => 'Jean']);
        kintai_test_store_users(findMembership: fn() => ['id' => 1, 'store_id' => 1, 'user_id' => 5]);
        $this->storeStatsService->method('employeeStats')->willReturn([]);
        $response = $this->controller()->employeeStats($req);

        $this->assertSame(200, $response->getStatusCode());

        $audit = array_values(array_filter($log->on('audit'), fn(array $e) => $e['message'] === 'employee_stats.viewed'));
        $this->assertCount(1, $audit);
        $this->assertSame('user', $audit[0]['context']['resource_type']);
        $this->assertSame(5, $audit[0]['context']['resource_id']);
    }

    /** @return array<string, array{string, bool}> */
    public static function memberRedirects(): array
    {
        return [
            'fiche employé (champ caché)' => ['/admin/users/057/edit', true],
            'site externe'                => ['https://evil.example/', false],
            'origine relative au schéma'  => ['//evil.example/', false],
            'antislash'                   => ['/\\evil.example/', false],
        ];
    }

    /** Audit du 01/10/2026 : la destination postée après l'ajout d'un membre n'était pas contrôlée du tout. */
    #[\PHPUnit\Framework\Attributes\DataProvider('memberRedirects')]
    public function testAddMemberOnlyRedirectsToInternalPaths(string $redirectTo, bool $kept): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/stores/1/members', 'SCRIPT_NAME' => '/index.php'];
        $_POST   = ['user_id' => '0', 'redirect_to' => $redirectTo];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['store' => 1]);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Test Store'] : null);
        $response = $this->controller()->addMember($req);

        $location = (string) ($response->headers->get('Location', ''));
        $this->assertSame(302, $response->getStatusCode());
        $kept
            ? $this->assertSame($redirectTo, $location)
            : $this->assertStringEndsWith('/admin/stores/1/edit', $location);
    }

    /** Prépare un gérant (id 2) du magasin 1 qui ne détient que shifts.view, et deux rôles assignables. */
    private function prepareManagerScenario(array $post): Request
    {
        $_POST   = $post;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/stores/1/members/9/role', 'SCRIPT_NAME' => '/index.php'];
        kintai_test_assignments(findByUser: fn(int $uid) => $uid === 2
            ? [['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 1]]
            : [], findByScope: fn() => [
            ['id' => 5, 'user_id' => 7, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => match ($id) {
            10      => ['id' => 10, 'is_system' => 1],
            default => ['id' => $id, 'is_system' => 0],
        }, getPermissions: fn(int $id) => match ($id) {
            20, 31 => ['shifts.view'],
            30      => ['shifts.view', 'shifts.update'],
            default => [],
        }, getGlobalPermissionKeys: static fn() => []);
        kintai_test_stores(findById: fn() => ['id' => 1]);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 2]);
        $req->attributes->set('managed_store_ids', [1]);
        $req->setRouteParams(['store' => 1, 'mid' => 9]);
        return $req;
    }

    /** Audit du 13/10/2026 : un gérant pouvait s'attribuer un rôle plus puissant que le sien. */
    public function testUpdateMemberRoleRefusesRoleExceedingCallerPermissions(): void
    {
        $req = $this->prepareManagerScenario(['role_id' => '30']);
        kintai_test_store_users(findById: fn(int $id) => $id === 9 ? ['id' => 9, 'store_id' => 1, 'user_id' => 2] : null);
        $this->users->method('findById')->willReturn(['id' => 2]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->updateMemberRole($req);
    }

    public function testUpdateMemberRoleRefusesToTouchAnOwnerAccount(): void
    {
        $req = $this->prepareManagerScenario(['role_id' => '31']);
        kintai_test_store_users(findById: fn(int $id) => $id === 9 ? ['id' => 9, 'store_id' => 1, 'user_id' => 7] : null);
        $this->users->method('findById')->willReturn(['id' => 7]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->updateMemberRole($req);
    }

    public function testAddMemberRefusesUnknownUser(): void
    {
        $req = $this->prepareManagerScenario(['user_id' => '999', 'role_id' => '31']);
        $this->users->method('findById')->willReturn(null);

        $this->expectException(\App\Exceptions\NotFoundException::class);
        $this->controller()->addMember($req);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
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

    /**
     * Audit M12 : POST /admin/stores/{id}/edit sans is_active désactivait le magasin ; sans coordonnées ni
     * réglages, il les vidait ou les remettait aux valeurs par défaut.
     */
    public function testUpdateStoreKeepsFieldsAbsentFromTheRequest(): void
    {
        $_POST = ['name' => 'Tokyo Est'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['store' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? [
            'id' => 1, 'name' => 'Tokyo', 'code' => 'TKY', 'is_active' => 1, 'phone' => '03-0000-0000',
            'week_start_day' => 7, 'min_shift_minutes' => 60,
        ] : null);
        Store::saveFeatureDecisions(1, ['shifts' => false, 'photos' => true]);
        Store::saveImportSettings(1, ['col_start' => 2, 'base_hour' => 8]);
        Store::saveDeductionSettings(1, ['enabled' => 1, 'pension_rate' => 9.15]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $controller = new AdminStoreController(
            kintai_test_views(sys_get_temp_dir()),
            $this->users,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['messaging', 'daily-report', 'store-photos', 'timeoff', 'shift-swap', 'shift-claim', 'timeclock'])),
            new RoleAssignmentSyncService(),
            new PermissionService(),
        );

        $controller->updateStore($req);

        $this->assertSame('Tokyo Est', $captured['name']);
        $this->assertSame(1, $captured['is_active']);
        $this->assertSame('03-0000-0000', $captured['phone']);
        $this->assertSame('TKY', $captured['code']);
        $this->assertNull($captured['slug'], 'slug absent : le slug manuel existant est conservé');
        $this->assertSame([7, 60], [$captured['week_start_day'], $captured['min_shift_minutes']]);
        $this->assertSame([2, 8], [$captured['_excel_settings']['col_start'], $captured['_excel_settings']['base_hour']]);
        $this->assertTrue($captured['_deduction_settings']['enabled']);
        $this->assertSame(9.15, $captured['_deduction_settings']['pension_rate']);
        $this->assertSame(['shifts' => false], $captured['_features']);
    }

    /** Audit M13 : un gérant ne se retire pas lui-même de son magasin (il perdrait ses droits). */
    public function testAManagerCannotRemoveTheirOwnMembership(): void
    {
        $req = $this->prepareManagerScenario([]);
        kintai_test_store_users(findById: fn(int $id) => $id === 9 ? ['id' => 9, 'store_id' => 1, 'user_id' => 2] : null);
        $this->users->method('findById')->willReturn(['id' => 2]);
        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->controller()->removeMember($req);
    }

    /** Même un rôle qu'il aurait le droit d'attribuer : il ne change pas le sien. */
    public function testAManagerCannotChangeTheirOwnRole(): void
    {
        $req = $this->prepareManagerScenario(['role_id' => '31']);
        kintai_test_store_users(findById: fn(int $id) => $id === 9 ? ['id' => 9, 'store_id' => 1, 'user_id' => 2] : null);
        $this->users->method('findById')->willReturn(['id' => 2]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->controller()->updateMemberRole($req);
    }

    /**
     * Étape 2e : une fonctionnalité déclarée par un bundle actif (Bundle::storeFeatures()) est proposée et enregistrée ;
     * une clé d'un bundle aujourd'hui inactif, déjà enregistrée pour le magasin, est conservée.
     */
    public function testUpdateStoreSavesBundleDeclaredFeatureAndKeepsUnknownOnes(): void
    {
        $_POST = ['name' => 'Test Store', 'feature_shifts' => '1', 'feature_acme_board' => '1'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['store' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Test Store'] : null);
        Store::saveFeatureDecisions(1, ['shifts' => true, 'gone_bundle_feature' => true]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $acme = new class extends \App\Bundles\Bundle {
            public function __construct() {}

            public function getName(): string
            {
                return 'acme';
            }

            public function register(): void {}

            public function storeFeatures(): array
            {
                return [new \kintai\Contract\Extension\StoreFeature('acme_board', 'acme_board')];
            }
        };
        $manager = (new \ReflectionClass(\App\BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['acme' => $acme]);

        $controller = new AdminStoreController(
            kintai_test_views(sys_get_temp_dir()),
            $this->users,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(new \App\Extension\ExtensionRegistry($manager), $manager),
            new RoleAssignmentSyncService(),
            new PermissionService(),
        );

        $this->assertSame(302, $controller->updateStore($req)->getStatusCode());
        $this->assertSame(['shifts' => true, 'acme_board' => true], $captured['_features']);
    }

    private function controller(): AdminStoreController
    {
        if ($this->controller === null) {
            $view = kintai_test_views(sys_get_temp_dir());
            $this->controller = new AdminStoreController(
                $view,
                $this->users,
                new AuditLogger(),
                $this->createStub(StoreServiceInterface::class),
                $this->storeStatsService,
                new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['messaging', 'daily-report', 'store-photos'])),
                new RoleAssignmentSyncService(),
                new PermissionService(),
            );
        }

        return $this->controller;
    }

}
