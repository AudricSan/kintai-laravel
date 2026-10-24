<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Tests\Support\FakeBundleManagerFactory;
use kintai\Core\Extension\StoreFeatures;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Core\Services\StoreServiceInterface;
use kintai\Core\Services\StoreStatsServiceInterface;
use kintai\UI\Controller\Web\Staff\AdminStoreController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminStoreControllerTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private UserRepositoryInterface&Stub $users;
    private StoreStatsServiceInterface&Stub $storeStatsService;
    private LogRepositoryInterface&Stub $logRepo;
    private RoleRepositoryInterface&Stub $roles;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private ?AdminStoreController $controller = null;

    protected function setUp(): void
    {
        $this->ensureViewFile('staff.stores');
        $this->ensureViewFile('staff.stores-form');
        $this->ensureViewFile('staff.employee-stats');
        $this->ensureViewFile('layout.app');
        
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->storeStatsService = $this->createStub(StoreStatsServiceInterface::class);
        $this->roles = $this->createStub(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);

        // AuditLogger::log() délègue à Log::record() -> LogRepositoryInterface::record().
        $this->logRepo = $this->createStub(LogRepositoryInterface::class);
        $this->registerLogRepository();
    }

    /** Branche $this->logRepo derrière Log::record() ; à rappeler quand un test le remplace par un mock. */
    private function registerLogRepository(): void
    {
        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->logRepo);
        Log::setContainer($container);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminStoreController
    {
        if ($this->controller === null) {
            $view = new ViewRenderer(sys_get_temp_dir());
            $this->controller = new AdminStoreController(
                $view,
                $this->users,
                $this->stores,
                $this->storeUsers,
                new AuditLogger(),
                $this->createStub(StoreServiceInterface::class),
                $this->storeStatsService,
                new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['messaging', 'daily-report', 'store-photos'])),
                new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
                new PermissionService($this->roleAssignments, $this->roles),
            );
        }

        return $this->controller;
    }

    /** Audit n°8 : quota de magasins atteint → retour au formulaire (erreur de validation), plus une page 403. */
    public function testStoreQuotaReachedSendsBackToTheFormInsteadOfA403(): void
    {
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('createStore')->willThrowException(new \kintai\Core\Exceptions\PlanLimitExceededException('1 magasin maximum'));
        $controller = new AdminStoreController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $this->stores,
            $this->storeUsers,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs([])),
            new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
            new PermissionService($this->roleAssignments, $this->roles),
        );
        $_POST = ['code' => 'NEW', 'name' => 'Nouveau'];
        $req = new Request();
        $_POST = [];
        $req->setAttribute('managed_store_ids', null);
        $req->setAttribute('auth_user', ['id' => 1]);

        try {
            $controller->storeStore($req);
            $this->fail('ValidationException attendue');
        } catch (\kintai\Core\Exceptions\ValidationException $e) {
            $this->assertSame(['store' => '1 magasin maximum'], $e->errors);
        }
    }

    public function testStoresRendersList(): void
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->stores->method('findAll')->willReturn([]);

        $response = $this->controller()->stores($req);

        $this->assertSame(200, $response->status());
    }

    public function testCreateStoreRendersForm(): void
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $this->controller()->createStore($req);

        $this->assertSame(200, $response->status());
    }

    public function testEditStoreRendersForm(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => 1]);

        $store = [
            'id' => 1, 'name' => 'Test Store', 'currency' => 'JPY',
            'week_start_day' => 1, 'timezone' => 'Asia/Tokyo',
        ];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($store);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn(['shifts', 'timeclock']);
        $this->stores->expects($this->atLeastOnce())->method('getImportSettings')->with(1)->willReturn([]);
        $this->stores->method('getDeductionSettings')->willReturn([]);
        $this->storeUsers->method('findByStore')->willReturn([]);
        $this->users->method('findAll')->willReturn([]);

        $response = $this->controller()->editStore($req);

        $this->assertSame(200, $response->status());
    }

    public function testEditStoreRendersFormWhenABundleIsDisabledInstanceWide(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $controller = new AdminStoreController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $this->stores,
            $this->storeUsers,
            new AuditLogger(),
            $this->createStub(StoreServiceInterface::class),
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['daily-report'])), // messaging et store-photos désactivés
            new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
            new PermissionService($this->roleAssignments, $this->roles),
        );

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => 1]);

        $store = ['id' => 1, 'name' => 'Test Store', 'currency' => 'JPY', 'week_start_day' => 1, 'timezone' => 'Asia/Tokyo'];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($store);
        $this->stores->expects($this->atLeastOnce())->method('getFeatures')->with(1)->willReturn(['shifts', 'timeclock', 'messages']);
        $this->stores->expects($this->atLeastOnce())->method('getImportSettings')->with(1)->willReturn([]);
        $this->stores->method('getDeductionSettings')->willReturn([]);
        $this->storeUsers->method('findByStore')->willReturn([]);
        $this->users->method('findAll')->willReturn([]);

        $response = $controller->editStore($req);

        $this->assertSame(200, $response->status());
    }

    public function testUpdateStorePersistsPhotosFeatureSlug(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        // Cases décochées : le champ caché envoie « 0 » (audit M12 : absent = inchangé).
        $_POST = ['name' => 'Test Store', 'feature_photos' => '1'] + array_fill_keys(
            ['feature_shifts', 'feature_timeclock', 'feature_timeoff', 'feature_swaps', 'feature_open_shifts', 'feature_messages', 'feature_daily_reports', 'feature_notes'],
            '0'
        );
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => '1']);

        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Test Store']);
        // Magasin déjà configuré : décisions enregistrées pour plusieurs fonctionnalités.
        $this->stores->expects($this->atLeastOnce())->method('getFeatureDecisions')->with(1)->willReturn([
            'shifts' => true, 'timeclock' => true, 'photos' => true, 'messages' => false,
        ]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $controller = new AdminStoreController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $this->stores,
            $this->storeUsers,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            // Tous les bundles de fonctionnalités par-store activés, pour que ce test ne
            // couvre que la soumission normale d'une case cochée (voir
            // testUpdateStorePreservesFeatureHiddenByDisabledBundle pour le cas masqué).
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['messaging', 'daily-report', 'store-photos', 'timeoff', 'shift-swap', 'shift-claim', 'timeclock'])),
            new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
            new PermissionService($this->roleAssignments, $this->roles),
        );

        $response = $controller->updateStore($req);

        $this->assertSame(302, $response->status());
        $this->assertNotNull($captured);
        // Seule la fonctionnalité proposée (Core) reçoit une décision ; celles des bundles absents ne sont pas
        // envoyées et gardent leur décision enregistrée (saveFeatureDecisions ne touche qu'aux clés données).
        $this->assertSame(['shifts' => false], $captured['_features']);
    }

    public function testUpdateStorePreservesFeatureHiddenByDisabledBundle(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        // "messages" et "photos" dépendent de bundles désactivés au niveau instance : leurs
        // cases ne sont pas rendues dans le formulaire, donc jamais soumises. Elles doivent
        // survivre à la sauvegarde au lieu d'être silencieusement décochées (régression
        // constatée en prod : éditer un store désactivait "photos" pour de bon).
        $_POST = ['name' => 'Test Store', 'feature_shifts' => '1'];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => '1']);

        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Test Store']);
        $this->stores->expects($this->atLeastOnce())->method('getFeatureDecisions')->with(1)->willReturn([]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $controller = new AdminStoreController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $this->stores,
            $this->storeUsers,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['daily-report'])), // messaging et store-photos désactivés
            new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
            new PermissionService($this->roleAssignments, $this->roles),
        );

        $response = $controller->updateStore($req);

        $this->assertSame(302, $response->status());
        $this->assertNotNull($captured);
        // Magasin jamais configuré : seule la case proposée est décidée ; messages et photos n'ont aucune décision et
        // restent donc actifs à la réactivation de leur bundle (régression du point 11 de la revue du 15/10/2026).
        $this->assertSame(['shifts' => true], $captured['_features']);
    }

    public function testEmployeeStatsLogsConsultation(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->logRepo = $this->createMock(LogRepositoryInterface::class);
        $this->registerLogRepository();
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'uid' => '5']);

        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Store A', 'currency' => 'JPY']);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'last_name' => 'Dupont', 'first_name' => 'Jean']);
        $this->storeUsers->expects($this->atLeastOnce())->method('findMembership')->with(1, 5)->willReturn(['id' => 1, 'store_id' => 1, 'user_id' => 5]);
        $this->storeStatsService->method('employeeStats')->willReturn([]);

        $this->logRepo->expects($this->once())->method('record')->with(
            $this->anything(), $this->anything(), $this->anything(),
            'employee_stats.viewed', 'user', 5,
            $this->anything(), $this->anything(), 1,
            $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(),
        );

        $response = $this->controller()->employeeStats($req);

        $this->assertSame(200, $response->status());
    }

    /** @return array<string, array{string, bool}> */
    public static function memberRedirects(): array
    {
        return [
            'fiche employé (champ caché)' => ['/admin/users/057/edit?success=member_added', true],
            'site externe'                => ['https://evil.example/', false],
            'origine relative au schéma'  => ['//evil.example/', false],
            'antislash'                   => ['/\\evil.example/', false],
        ];
    }

    /** Audit du 01/10/2026 : la destination postée après l'ajout d'un membre n'était pas contrôlée du tout. */
    #[\PHPUnit\Framework\Attributes\DataProvider('memberRedirects')]
    public function testAddMemberOnlyRedirectsToInternalPaths(string $redirectTo, bool $kept): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/stores/1/members', 'SCRIPT_NAME' => '/index.php'];
        $_POST   = ['user_id' => '0', 'redirect_to' => $redirectTo];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => 1]);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Test Store']);

        $response = $this->controller()->addMember($req);

        $location = (string) ((fn() => $this->headers['Location'] ?? '')->call($response));
        $this->assertSame(302, $response->status());
        $kept
            ? $this->assertSame($redirectTo, $location)
            : $this->assertStringEndsWith('/admin/stores/1/edit?success=member_added', $location);
    }

    /** Prépare un gérant (id 2) du magasin 1 qui ne détient que shifts.view, et deux rôles assignables. */
    private function prepareManagerScenario(array $post): Request
    {
        $_POST   = $post;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/stores/1/members/9/role', 'SCRIPT_NAME' => '/index.php'];
        $this->roleAssignments->method('findByUser')->willReturnCallback(fn(int $uid) => $uid === 2
            ? [['id' => 1, 'user_id' => 2, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => 1]]
            : []);
        $this->roleAssignments->method('findByScope')->willReturn([
            ['id' => 5, 'user_id' => 7, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->roles->method('findById')->willReturnCallback(fn(int $id) => match ($id) {
            10      => ['id' => 10, 'is_system' => 1],
            default => ['id' => $id, 'is_system' => 0],
        });
        $this->roles->method('getPermissions')->willReturnCallback(fn(int $id) => match ($id) {
            20, 31 => ['shifts.view'],
            30      => ['shifts.view', 'shifts.update'],
            default => [],
        });
        $this->roles->method('getGlobalPermissionKeys')->willReturn([]);
        $this->stores->method('findById')->willReturn(['id' => 1]);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 2]);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => 1, 'mid' => 9]);
        return $req;
    }

    /** Audit du 13/10/2026 : un gérant pouvait s'attribuer un rôle plus puissant que le sien. */
    public function testUpdateMemberRoleRefusesRoleExceedingCallerPermissions(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $req = $this->prepareManagerScenario(['role_id' => '30']);
        $this->storeUsers->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn(['id' => 9, 'store_id' => 1, 'user_id' => 2]);
        $this->users->method('findById')->willReturn(['id' => 2]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->updateMemberRole($req);
    }

    public function testUpdateMemberRoleRefusesToTouchAnOwnerAccount(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $req = $this->prepareManagerScenario(['role_id' => '31']);
        $this->storeUsers->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn(['id' => 9, 'store_id' => 1, 'user_id' => 7]);
        $this->users->method('findById')->willReturn(['id' => 7]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->updateMemberRole($req);
    }

    public function testAddMemberRefusesUnknownUser(): void
    {
        $req = $this->prepareManagerScenario(['user_id' => '999', 'role_id' => '31']);
        $this->users->method('findById')->willReturn(null);

        $this->expectException(\kintai\Core\Exceptions\NotFoundException::class);
        $this->controller()->addMember($req);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        Log::reset();
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
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $_POST = ['name' => 'Tokyo Est'];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => '1']);

        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn([
            'id' => 1, 'name' => 'Tokyo', 'code' => 'TKY', 'is_active' => 1, 'phone' => '03-0000-0000',
            'week_start_day' => 7, 'min_shift_minutes' => 60,
        ]);
        $this->stores->method('getFeatureDecisions')->willReturn(['shifts' => false, 'photos' => true]);
        $this->stores->method('getImportSettings')->willReturn(['col_start' => 2, 'base_hour' => 8]);
        $this->stores->method('getDeductionSettings')->willReturn(['enabled' => 1, 'pension_rate' => 9.15]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $controller = new AdminStoreController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $this->stores,
            $this->storeUsers,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(null, FakeBundleManagerFactory::withActiveSlugs(['messaging', 'daily-report', 'store-photos', 'timeoff', 'shift-swap', 'shift-claim', 'timeclock'])),
            new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
            new PermissionService($this->roleAssignments, $this->roles),
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
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $req = $this->prepareManagerScenario([]);
        $this->storeUsers->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn(['id' => 9, 'store_id' => 1, 'user_id' => 2]);
        $this->users->method('findById')->willReturn(['id' => 2]);
        $this->storeUsers->expects($this->never())->method('delete');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->controller()->removeMember($req);
    }

    /** Même un rôle qu'il aurait le droit d'attribuer : il ne change pas le sien. */
    public function testAManagerCannotChangeTheirOwnRole(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $req = $this->prepareManagerScenario(['role_id' => '31']);
        $this->storeUsers->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn(['id' => 9, 'store_id' => 1, 'user_id' => 2]);
        $this->users->method('findById')->willReturn(['id' => 2]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->controller()->updateMemberRole($req);
    }

    /**
     * Étape 2e : une fonctionnalité déclarée par un bundle actif (Bundle::storeFeatures()) est proposée et enregistrée ;
     * une clé d'un bundle aujourd'hui inactif, déjà enregistrée pour le magasin, est conservée.
     */
    public function testUpdateStoreSavesBundleDeclaredFeatureAndKeepsUnknownOnes(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $_POST = ['name' => 'Test Store', 'feature_shifts' => '1', 'feature_acme_board' => '1'];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => '1']);

        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Test Store']);
        $this->stores->expects($this->atLeastOnce())->method('getFeatureDecisions')->with(1)->willReturn(['shifts' => true, 'gone_bundle_feature' => true]);

        $captured = null;
        $storeService = $this->createStub(StoreServiceInterface::class);
        $storeService->method('updateStore')->willReturnCallback(function (int $id, array $data) use (&$captured) {
            $captured = $data;
            return ['id' => $id] + $data;
        });

        $acme = new class extends \kintai\Core\BundleContract\Bundle {
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
        $manager = (new \ReflectionClass(\kintai\Core\BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['acme' => $acme]);

        $controller = new AdminStoreController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $this->stores,
            $this->storeUsers,
            new AuditLogger(),
            $storeService,
            $this->storeStatsService,
            new StoreFeatures(new \kintai\Core\Extension\ExtensionRegistry($manager), $manager),
            new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
            new PermissionService($this->roleAssignments, $this->roles),
        );

        $this->assertSame(302, $controller->updateStore($req)->status());
        $this->assertSame(['shifts' => true, 'acme_board' => true], $captured['_features']);
    }
}
