<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\DailyReportRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\Log;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\UI\Controller\Web\Staff\AdminUserController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class AdminUserControllerTest extends TestCase
{
    private ?AdminUserController $controller = null;
    private UserRepositoryInterface&Stub $users;
    private StoreRepositoryInterface&Stub $stores;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private LogRepositoryInterface&Stub $logRepo;
    private RoleRepositoryInterface&Stub $roles;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private ShiftTypeRepositoryInterface&Stub $shiftTypes;
    private UserShiftTypeRateRepositoryInterface&Stub $userRates;
    protected function setUp(): void
    {
        $this->ensureViewFile('staff.users');
        $this->ensureViewFile('staff.users-form');
        $this->ensureViewFile('staff.users-export-pdf');
        $this->ensureViewFile('layout.app');
        
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->roles = $this->createStub(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $this->userRates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);

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
    private function controller(): AdminUserController
    {
        if ($this->controller === null) {
            $view = new ViewRenderer(sys_get_temp_dir());
            $this->controller = new AdminUserController(
                $view,
                $this->users,
                $this->stores,
                $this->createStub(ShiftRepositoryInterface::class),
                $this->shiftTypes,
                $this->storeUsers,
                $this->userRates,
                new AuditLogger(),
                new RoleAssignmentSyncService($this->roles, $this->roleAssignments),
                new PermissionService($this->roleAssignments, $this->roles),
                new PlanLimitService(
                    $this->stores,
                    $this->users,
                    new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
                ),
                new OwnerGuard(new PermissionService($this->roleAssignments, $this->roles), $this->users),
                null,
                new UserAnonymizer(
                    $this->users,
                    $this->roleAssignments,
                    new CredentialRevoker($this->createStub(RememberTokenRepositoryInterface::class), $this->createStub(ApiTokenRepositoryInterface::class)),
                    sys_get_temp_dir(),
                ),
            );
        }

        return $this->controller;
    }

    public function testUsersRenders(): void
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $this->controller()->users($req);

        $this->assertSame(200, $response->status());
    }

    /**
     * La recherche par nom est appliquée entièrement côté client
     * (kana-search.js / users-list-search.js, voir CONTRIBUTING.md "Filter
     * bars apply instantly") pour rester instantanée et sans rechargement de
     * page. Le contrôleur ne doit donc plus retirer d'utilisateurs de la
     * liste quand `?search=` est présent — il ne sert qu'à pré-remplir le
     * champ pour un lien direct. La correspondance romaji/hiragana/kana est
     * couverte par KanaSearchTest (le service PHP reste utilisé nulle part
     * ici mais son portage JS, kana-search.js, réplique le même algorithme).
     */
    public function testUsersSearchDoesNotErrorAndDoesNotFilterServerSide(): void
    {
        $this->users->method('findAll')->willReturn([
            ['id' => 1, 'first_name' => '太郎', 'last_name' => '山田', 'furigana_last_name' => 'ヤマダ', 'furigana_first_name' => 'タロウ', 'employee_code' => 'EMP001', 'email' => 'yamada@test.com'],
            ['id' => 2, 'first_name' => 'Paul', 'last_name' => 'Martin', 'employee_code' => 'EMP002', 'email' => 'paul@test.com'],
        ]);

        $_GET = ['search' => 'yamada'];
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $this->controller()->users($req);

        $this->assertSame(200, $response->status());
        $_GET = [];
    }

    // -------------------------------------------------------------------------
    // Export employés (item 3)
    // -------------------------------------------------------------------------

    public function testExportUsersJsonReturnsExpectedFields(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->userRates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->users->method('findAll')->willReturn([
            ['id' => 5, 'employee_code' => 'E005', 'last_name' => 'Dupont', 'first_name' => 'Jean',
             'display_name' => 'Jean Dupont', 'email' => 'jean@test.com', 'phone' => '0102030405',
             'mobile_phone' => '0601020304', 'postal_code' => '75001', 'address' => 'Rue X',
             'is_admin' => false, 'is_active' => 1, 'deleted_at' => null],
        ]);
        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store A']]);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByUser')->with(5)->willReturn([['store_id' => 1]]);
        $this->userRates->expects($this->atLeastOnce())->method('findByUser')->with(5)->willReturn([['shift_type_id' => 2, 'hourly_rate' => 1200]]);
        $this->shiftTypes->method('findAll')->willReturn([['id' => 2, 'name' => 'Salle']]);

        $response = $this->controller()->exportUsersJson($req);
        $this->assertSame(200, $response->status());

        $data = json_decode($response->body(), true)['data'];
        $this->assertCount(1, $data);
        $this->assertSame('E005', $data[0]['employee_code']);
        $this->assertSame('jean@test.com', $data[0]['email']);
        $this->assertSame('0601020304', $data[0]['mobile_phone']);
        $this->assertSame(['Store A'], $data[0]['stores']);
        $this->assertTrue($data[0]['is_active']);
        $this->assertSame('Salle', $data[0]['hourly_rates'][0]['shift_type']);
        $this->assertEquals(1200, $data[0]['hourly_rates'][0]['hourly_rate']);
    }

    public function testExportUsersJsonRespectsStoreFilter(): void
    {
        $_GET['store_id'] = '1';
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->users->method('findAll')->willReturn([
            ['id' => 5, 'display_name' => 'In Store', 'email' => 'a@test.com', 'is_active' => 1],
            ['id' => 6, 'display_name' => 'Other Store', 'email' => 'b@test.com', 'is_active' => 1],
        ]);
        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store A'], ['id' => 2, 'name' => 'Store B']]);
        $this->storeUsers->method('findByUser')->willReturnMap([
            [5, [['store_id' => 1]]],
            [6, [['store_id' => 2]]],
        ]);
        $this->userRates->method('findByUser')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);

        $response = $this->controller()->exportUsersJson($req);
        $data = json_decode($response->body(), true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame('In Store', $data[0]['display_name']);
    }

    public function testExportUsersPdfReturnsHtmlPreview(): void
    {
        // mPDF lit $_SERVER['PHP_SELF'], absent en environnement CLI/tests.
        $_SERVER['PHP_SELF'] ??= '/index.php';

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->users->method('findAll')->willReturn([]);
        $this->stores->method('findAll')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);

        $response = $this->controller()->exportUsersPdf($req);

        $this->assertSame(200, $response->status());
        $this->assertStringNotContainsString('%PDF', $response->body());
    }

    public function testExportUsersPdfDownloadReturnsPdfResponse(): void
    {
        // mPDF lit $_SERVER['PHP_SELF'], absent en environnement CLI/tests.
        $_SERVER['PHP_SELF'] ??= '/index.php';

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->users->method('findAll')->willReturn([]);
        $this->stores->method('findAll')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);

        $response = $this->controller()->exportUsersPdfDownload($req);

        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith('%PDF', $response->body());
    }

    public function testEditUserRenders(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => 1]);

        $this->users->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'email' => 'test@test.com']);
        $this->stores->method('findAll')->willReturn([]);
        $this->storeUsers->method('findByUser')->willReturn([]);

        $response = $this->controller()->editUser($req);

        $this->assertSame(200, $response->status());
    }

    public function testEditUserLogsProfileConsultation(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->logRepo = $this->createMock(LogRepositoryInterface::class);
        $this->registerLogRepository();
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => 5]);

        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'employee@test.com']);
        $this->stores->method('findAll')->willReturn([]);
        $this->storeUsers->method('findByUser')->willReturn([]);

        $this->logRepo->expects($this->once())->method('record')->with(
            $this->anything(), $this->anything(), $this->anything(),
            'user.viewed', 'user', 5,
            $this->anything(), $this->anything(), $this->anything(),
            $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(),
        );

        $response = $this->controller()->editUser($req);

        $this->assertSame(200, $response->status());
    }

    // -------------------------------------------------------------------------
    // quickCreateUser
    // -------------------------------------------------------------------------

    public function testQuickCreateUserCreatesUserAndReturnsJson(): void
    {
        $req = $this->makePostRequest([
            'display_name' => 'John Doe',
            'email'        => 'john@example.com',
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'password'     => 'secret',
            'color'        => '#FF0000',
            'is_admin'     => '0',
            'furigana_last_name'  => 'ドウ',
            'furigana_first_name' => 'ジョン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->users->method('save')->willReturn(['id' => 7, 'display_name' => 'John Doe']);

        $response = $this->controller()->quickCreateUser($req);

        $this->assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(7, $data['user']['id']);
    }

    public function testQuickCreateUserFailsWhenFreePlanEmployeeLimitReached(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countActive')->willReturn(15);
        $req = $this->makePostRequest([
            'display_name' => 'John Doe',
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'furigana_last_name'  => 'ドウ',
            'furigana_first_name' => 'ジョン',
        ]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->quickCreateUser($req);

        $this->assertSame(403, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('plan_limit_employees', $data['error']);
    }

    public function testQuickCreateUserFailsWhenNameMissing(): void
    {
        $req = $this->makePostRequest([
            'first_name' => '',
            'display_name' => '',
        ]);

        $response = $this->controller()->quickCreateUser($req);

        $this->assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['success']);
    }

    /** Le furigana est désormais obligatoire pour tous les chemins de création. */
    public function testQuickCreateUserFailsWhenFuriganaMissing(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makePostRequest([
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'display_name' => 'John',
            'email'        => 'john@example.com',
        ]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->quickCreateUser($req);

        $this->assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('furigana_required', $data['error']);
    }

    public function testQuickCreateUserGeneratesDisplayName(): void
    {
        $req = $this->makePostRequest([
            'first_name' => 'Jane',
            'last_name'  => 'Smith',
            'display_name' => '',
            'email'      => 'jane@example.com',
            'password'   => 'pass',
            'furigana_last_name'  => 'スミス',
            'furigana_first_name' => 'ジェーン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $captured = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 8];
        });

        $this->controller()->quickCreateUser($req);

        $this->assertNotNull($captured);
        $this->assertSame('Smith Jane', $captured['display_name']);
    }

    public function testQuickCreateUserGeneratesEmailWhenMissing(): void
    {
        $req = $this->makePostRequest([
            'first_name'   => 'Jane',
            'last_name'    => 'Smith',
            'display_name' => 'Jane Smith',
            'email'        => '',
            'password'     => 'pass',
            'furigana_last_name'  => 'スミス',
            'furigana_first_name' => 'ジェーン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->users->method('findByEmail')->willReturn(null);
        $captured = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 8];
        });

        $this->controller()->quickCreateUser($req);

        $this->assertNotNull($captured);
        $this->assertStringEndsWith('@kintai.local', $captured['email']);
        $this->assertStringContainsString('jane.smith', $captured['email']);
    }

    public function testQuickCreateUserReturns409OnEmployeeCodeConflict(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name'   => 'John',
            'first_name'     => 'John',
            'last_name'      => 'Doe',
            'email'          => 'john@example.com',
            'employee_code'  => 'EMP001',
            'password'       => 'pass',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
        ]);

        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP001')->willReturn(['id' => 99]);

        $response = $this->controller()->quickCreateUser($req);

        $this->assertSame(409, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('employee_code_taken', $data['error']);
    }

    /**
     * Régression : un email fourni explicitement (pas auto-généré) qui existe
     * déjà en base faisait planter la création avec une exception SQL non
     * gérée (contrainte unique) au lieu d'un message clair — typiquement
     * quand l'import de shifts ne reconnaît pas un employé déjà existant.
     */
    public function testQuickCreateUserReturns409OnEmailConflict(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'email'        => 'existing@example.com',
            'password'     => 'pass',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
        ]);

        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('existing@example.com')->willReturn(['id' => 99]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->quickCreateUser($req);

        $this->assertSame(409, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('email_taken', $data['error']);
    }

    public function testQuickCreateUserGeneratesTempPasswordWhenEmpty(): void
    {
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'email'        => 'john@example.com',
            'password'     => '',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $captured = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 9];
        });

        $this->controller()->quickCreateUser($req);

        $this->assertNotNull($captured);
        $this->assertStringStartsWith('$2y$', $captured['password_hash']);
    }

    public function testQuickCreateUserAssignsStoreMembership(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name'  => 'John',
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'email'         => 'john@example.com',
            'password'      => 'pass',
            'store_id'      => '3',
            'store_role_id' => '2',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->users->method('save')->willReturn(['id' => 10, 'display_name' => 'John']);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['employees.view']);
        $this->roleAssignments->method('findByUser')->willReturn([]);

        $capturedStoreUser = null;
        $this->storeUsers->method('save')->willReturnCallback(function (array $d) use (&$capturedStoreUser) {
            $capturedStoreUser = $d;
            return $d;
        });

        $this->controller()->quickCreateUser($req);

        $this->assertNotNull($capturedStoreUser);
        $this->assertSame(3, $capturedStoreUser['store_id']);
        $this->assertSame(10, $capturedStoreUser['user_id']);
    }

    /**
     * AuthService::managedStoreIds() lit désormais role_assignments : sans
     * cette synchronisation, un manager créé via l'import de shifts ne serait
     * jamais reconnu comme gestionnaire de son store.
     */
    public function testQuickCreateUserSyncsRoleAssignmentForStoreMembership(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name'  => 'John',
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'email'         => 'john@example.com',
            'password'      => 'pass',
            'store_id'      => '3',
            'store_role_id' => '2',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->users->method('save')->willReturn(['id' => 10, 'display_name' => 'John']);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(null);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['employees.view']);
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->roleAssignments->expects($this->once())->method('assign')->with(10, 2, 'store', 3);

        $this->controller()->quickCreateUser($req);
    }

    /** Id de rôle absent/invalide → repli sur le rôle par défaut (premier rôle sans permission). */
    public function testQuickCreateUserFallsBackToDefaultRoleWhenRoleIdMissing(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'email'        => 'john@example.com',
            'password'     => 'pass',
            'store_id'     => '3',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->users->method('save')->willReturn(['id' => 10, 'display_name' => 'John']);
        $this->roles->method('findAll')->willReturn([
            ['id' => 2, 'name' => 'Manager', 'is_system' => 0],
            ['id' => 3, 'name' => 'Employé', 'is_system' => 0],
        ]);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(3)->willReturn(['id' => 3, 'name' => 'Employé', 'is_system' => 0]);
        $this->roles->method('getPermissions')->willReturnMap([
            [2, ['employees.view']],
            [3, []],
        ]);
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->roleAssignments->expects($this->once())->method('assign')->with(10, 3, 'store', 3);

        $capturedStoreUser = null;
        $this->storeUsers->method('save')->willReturnCallback(function (array $d) use (&$capturedStoreUser) {
            $capturedStoreUser = $d;
            return $d;
        });

        $this->controller()->quickCreateUser($req);

        $this->assertNotNull($capturedStoreUser);
    }

    public function testQuickCreateUserSyncsOwnerRoleWhenIsAdminChecked(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'Jane',
            'first_name'   => 'Jane',
            'last_name'    => 'Doe',
            'email'        => 'jane@example.com',
            'password'     => 'pass',
            'is_admin'     => '1',
            'furigana_last_name'  => 'ジェーン',
            'furigana_first_name' => 'ジェーン',
        ]);
        // Requérant Owner : seul un Owner peut créer un autre compte Owner
        // (voir testQuickCreateUserIgnoresIsAdminWhenRequesterIsNotOwner).
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->users->method('save')->willReturn(['id' => 11, 'display_name' => 'Jane']);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->roleAssignments->expects($this->once())->method('assign')->with(11, 1, 'global', null);

        $this->controller()->quickCreateUser($req);
    }

    /**
     * Bug de sécurité corrigé : un requérant qui n'est PAS Owner (ex. un simple
     * détenteur de employees.create sur son store) ne doit pas pouvoir créer un
     * compte Owner en postant is_admin=1 — seul le statut Owner du REQUÉRANT
     * compte, jamais un champ posté par le client.
     */
    public function testQuickCreateUserIgnoresIsAdminWhenRequesterIsNotOwner(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'Jane',
            'first_name'   => 'Jane',
            'last_name'    => 'Doe',
            'email'        => 'jane@example.com',
            'password'     => 'pass',
            'is_admin'     => '1',
            'furigana_last_name'  => 'ジェーン',
            'furigana_first_name' => 'ジェーン',
        ]);
        $req->setAttribute('auth_user', ['id' => 2, 'is_admin' => false]);

        $this->users->method('save')->willReturn(['id' => 12, 'display_name' => 'Jane']);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->roleAssignments->expects($this->never())->method('assign');

        $this->controller()->quickCreateUser($req);
    }

    // -------------------------------------------------------------------------
    // storeUser — sélecteur "Rôle" unifié (Owner + rôles par store, remplace
    // l'ancien couple is_admin / store_role_id)
    // -------------------------------------------------------------------------

    /** Rôle Owner sélectionné : compte admin global, pas de rôle par store à synchroniser même avec un store choisi. */
    public function testStoreUserSyncsOwnerRoleAndSkipsStoreRoleWhenOwnerSelected(): void
    {
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'Jane',
            'last_name'    => 'Doe',
            'first_name'   => 'Jane',
            'email'        => 'jane@example.com',
            'furigana_last_name'  => 'ドウ',
            'furigana_first_name' => 'ジェーン',
            'role_id'      => '1',
            'store_id'     => '3',
        ]);
        $req->setAttribute('managed_store_ids', null);
        // Requérant Owner : seul un Owner peut créer un autre compte Owner
        // (voir testStoreUserIgnoresOwnerRoleSelectionWhenRequesterIsNotOwner).
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->roles->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->roleAssignments->expects($this->once())->method('assign')->with(20, 1, 'global', null);

        $captured = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 20];
        });
        // Aucun rôle par store à écrire : la synchronisation store-scope (findAssignableRole
        // rejette les rôles système) ne doit pas être sollicitée pour ce cas.
        $this->storeUsers->expects($this->once())->method('save')->willReturnCallback(fn(array $d) => $d);

        $this->controller()->storeUser($req);

        $this->assertNotNull($captured);
    }

    /**
     * Bug de sécurité corrigé : un requérant qui n'est pas Owner ne doit pas
     * pouvoir créer un compte Owner en sélectionnant le rôle système dans le
     * sélecteur "Rôle" unifié.
     */
    public function testStoreUserIgnoresOwnerRoleSelectionWhenRequesterIsNotOwner(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'Jane',
            'last_name'    => 'Doe',
            'first_name'   => 'Jane',
            'email'        => 'jane@example.com',
            'furigana_last_name'  => 'ドウ',
            'furigana_first_name' => 'ジェーン',
            'role_id'      => '1',
        ]);
        $req->setAttribute('managed_store_ids', [3]);
        $req->setAttribute('auth_user', ['id' => 2, 'is_admin' => false]);

        $this->roles->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Owner', 'is_system' => 1]);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->users->method('save')->willReturn(['id' => 22, 'display_name' => 'Jane']);
        $this->roleAssignments->expects($this->never())->method('assign');

        $this->controller()->storeUser($req);
    }

    /** Rôle par store sélectionné (Manager) : comportement inchangé, juste renommé store_role_id -> role_id. */
    public function testStoreUserSyncsStoreRoleWhenStoreRoleSelected(): void
    {
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'last_name'    => 'Doe',
            'first_name'   => 'John',
            'email'        => 'john@example.com',
            'furigana_last_name'  => 'ジョン',
            'furigana_first_name' => 'ジョン',
            'role_id'      => '2',
            'store_id'     => '3',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'name' => 'Manager', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['employees.view']);
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->roleAssignments->expects($this->once())->method('assign')->with(21, 2, 'store', 3);

        $this->users->method('save')->willReturn(['id' => 21, 'display_name' => 'John']);

        $capturedStoreUser = null;
        $this->storeUsers->method('save')->willReturnCallback(function (array $d) use (&$capturedStoreUser) {
            $capturedStoreUser = $d;
            return $d;
        });

        $this->controller()->storeUser($req);

        $this->assertNotNull($capturedStoreUser);
    }

    /** Nom affiché laissé vide (ou espaces) dans le formulaire : « Nom Prénom », jamais une chaîne vide. */
    public function testStoreUserFillsAnEmptyDisplayName(): void
    {
        $req = $this->makePostRequest([
            'display_name' => '   ',
            'last_name'    => 'Doe',
            'first_name'   => 'John',
            'email'        => 'john.doe@example.com',
            'furigana_last_name'  => 'ドウ',
            'furigana_first_name' => 'ジョン',
        ]);
        $req->setAttribute('managed_store_ids', null);
        $this->roleAssignments->method('findByUser')->willReturn([]);

        $captured = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured ??= $d;
            return $d + ['id' => 22];
        });

        $this->controller()->storeUser($req);

        $this->assertSame('Doe John', $captured['display_name']);
    }

    // -------------------------------------------------------------------------
    // storeUser / updateUser — conflit d'email
    // -------------------------------------------------------------------------

    public function testStoreUserRedirectsWithErrorWhenFreePlanEmployeeLimitReached(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countActive')->willReturn(15);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'last_name'    => 'Doe',
            'first_name'   => 'John',
            'furigana_last_name'  => 'ドウ',
            'furigana_first_name' => 'ジョン',
            'email'        => 'new@example.com',
        ]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->storeUser($req);

        $this->assertSame(302, $response->status());
    }

    public function testStoreUserRedirectsWithErrorOnEmailConflict(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'email'        => 'existing@example.com',
        ]);

        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('existing@example.com')->willReturn(['id' => 99]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->storeUser($req);

        $this->assertSame(302, $response->status());
    }

    /** Le nom et le prénom sont désormais obligatoires à la création (formulaire principal). */
    public function testStoreUserRedirectsWithErrorWhenNameMissing(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'email'        => 'new@example.com',
        ]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->storeUser($req);

        $this->assertSame(302, $response->status());
    }

    /** Le furigana est désormais obligatoire à la création (formulaire principal). */
    public function testStoreUserRedirectsWithErrorWhenFuriganaMissing(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makePostRequest([
            'display_name' => 'John',
            'last_name'    => 'Doe',
            'first_name'   => 'John',
            'email'        => 'new@example.com',
        ]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller()->storeUser($req);

        $this->assertSame(302, $response->status());
    }

    public function testUpdateUserRedirectsWithErrorWhenEmailTakenByAnotherUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com']);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('taken@example.com')->willReturn(['id' => 99]);
        $this->users->expects($this->never())->method('save');

        $req = $this->makePostRequest(['email' => 'taken@example.com']);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->updateUser($req);

        $this->assertSame(302, $response->status());
    }

    /** Le nom et le prénom sont désormais obligatoires à l'édition, sauf s'ils sont déjà en base. */
    public function testUpdateUserRedirectsWithErrorWhenNameMissing(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => null, 'first_name' => null]);
        $this->users->expects($this->never())->method('save');

        $req = $this->makePostRequest(['email' => 'me@example.com']);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->updateUser($req);

        $this->assertSame(302, $response->status());
    }

    /** Le furigana est désormais obligatoire à l'édition, sauf s'il est déjà en base. */
    public function testUpdateUserRedirectsWithErrorWhenFuriganaMissing(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => 'Doe', 'first_name' => 'John', 'furigana_last_name' => null, 'furigana_first_name' => null]);
        $this->users->expects($this->never())->method('save');

        $req = $this->makePostRequest(['email' => 'me@example.com']);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->updateUser($req);

        $this->assertSame(302, $response->status());
    }

    /** Un code employé déjà porté par un autre compte est refusé avec un message, pas par une erreur 500 de la base. */
    public function testUpdateUserRedirectsWithErrorWhenEmployeeCodeTakenByAnotherUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => 'Doe', 'first_name' => 'John', 'furigana_last_name' => 'ド', 'furigana_first_name' => 'ジ']);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP9')->willReturn(['id' => 99]);
        $this->users->expects($this->never())->method('save');

        $req = $this->makePostRequest(['email' => 'me@example.com', 'employee_code' => 'emp9']);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->updateUser($req);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('error=employee_code_taken', $response->headers()['Location'] ?? '');
    }

    /** Garder son propre code ne doit pas être pris pour un doublon. */
    public function testUpdateUserAllowsKeepingOwnEmployeeCode(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => 'Doe', 'first_name' => 'John', 'employee_code' => 'EMP9']);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP9')->willReturn(['id' => 5]);
        $this->users->expects($this->once())->method('save')->willReturn(['id' => 5]);

        $req = $this->makePostRequest(['email' => 'me@example.com', 'employee_code' => 'EMP9', 'furigana_last_name' => 'テスト', 'furigana_first_name' => 'テスト']);
        $req->setRouteParams(['id' => 5]);

        $this->assertSame(302, $this->controller()->updateUser($req)->status());
    }

    public function testUpdateUserAllowsKeepingOwnEmailUnchanged(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => 'Doe', 'first_name' => 'John']);
        $this->users->expects($this->never())->method('findByEmail');
        $this->users->expects($this->once())->method('save')->willReturn(['id' => 5]);

        $req = $this->makePostRequest([
            'email' => 'me@example.com',
            'furigana_last_name'  => 'テスト',
            'furigana_first_name' => 'テスト',
        ]);
        $req->setRouteParams(['id' => 5]);

        $response = $this->controller()->updateUser($req);

        $this->assertSame(302, $response->status());
    }

    // -------------------------------------------------------------------------
    // updateUser — statut Owner (is_admin) : uniquement modifiable par un
    // requérant déjà Owner (bug de sécurité corrigé, voir syncOwnerRole()).
    // -------------------------------------------------------------------------

    public function testUpdateUserSyncsOwnerRoleWhenRequesterIsOwner(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => 'Doe', 'first_name' => 'John']);
        $this->users->method('save')->willReturn(['id' => 5]);
        $this->roles->expects($this->atLeastOnce())->method('findBySlug')->with('owner')->willReturn(['id' => 1]);
        $this->roleAssignments->method('findByUser')->willReturn([]);
        $this->roleAssignments->expects($this->once())->method('assign')->with(5, 1, 'global', null);

        $req = $this->makePostRequest([
            'email' => 'me@example.com',
            'furigana_last_name'  => 'テスト',
            'furigana_first_name' => 'テスト',
            'is_admin' => '1',
        ]);
        $req->setRouteParams(['id' => 5]);
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->controller()->updateUser($req);
    }

    /**
     * Bug de sécurité corrigé : un requérant qui n'est pas Owner ne doit pas
     * pouvoir s'auto-promouvoir (ni promouvoir quiconque) en postant
     * is_admin=1 sur cette route — seule employees.update la protège
     * normalement, ce qui ne suffit pas à garantir que le requérant est Owner.
     */
    public function testUpdateUserIgnoresIsAdminWhenRequesterIsNotOwner(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'me@example.com', 'last_name' => 'Doe', 'first_name' => 'John']);
        $this->users->method('save')->willReturn(['id' => 5]);
        $this->roles->expects($this->never())->method('findBySlug');
        $this->roleAssignments->expects($this->never())->method('assign');
        $this->roleAssignments->expects($this->never())->method('revoke');

        $req = $this->makePostRequest([
            'email' => 'me@example.com',
            'furigana_last_name'  => 'テスト',
            'furigana_first_name' => 'テスト',
            'is_admin' => '1',
        ]);
        $req->setRouteParams(['id' => 5]);
        $req->setAttribute('auth_user', ['id' => 2, 'is_admin' => false]);

        $this->controller()->updateUser($req);
    }

    // -------------------------------------------------------------------------
    // checkEmployeeCode
    // -------------------------------------------------------------------------

    public function testCheckEmployeeCodeReturnsTrueWhenAvailable(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['code' => 'EMP999']);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP999')->willReturn(null);

        $response = $this->controller()->checkEmployeeCode($req);

        $this->assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertTrue($data['available']);
    }

    public function testCheckEmployeeCodeReturnsFalseWhenTaken(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['code' => 'EMP001']);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP001')->willReturn(['id' => 1]);

        $response = $this->controller()->checkEmployeeCode($req);

        $this->assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['available']);
    }

    public function testCheckEmployeeCodeNormalizesToUppercase(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['code' => 'emp001']);

        $this->users->expects($this->once())->method('findByEmployeeCode')->with('EMP001')->willReturn(['id' => 1]);

        $this->controller()->checkEmployeeCode($req);
    }

    public function testCheckEmployeeCodeAvailableWhenTakenByExcludedUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        // Édition : le code déjà pris par l'utilisateur en cours d'édition lui-même n'est pas un conflit.
        $req = $this->makeQueryRequest(['code' => 'EMP001', 'exclude_id' => '1']);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP001')->willReturn(['id' => 1]);

        $response = $this->controller()->checkEmployeeCode($req);

        $data = json_decode($response->body(), true);
        $this->assertTrue($data['available']);
    }

    public function testCheckEmployeeCodeUnavailableWhenTakenByAnotherUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['code' => 'EMP001', 'exclude_id' => '2']);
        $this->users->expects($this->atLeastOnce())->method('findByEmployeeCode')->with('EMP001')->willReturn(['id' => 1]);

        $response = $this->controller()->checkEmployeeCode($req);

        $data = json_decode($response->body(), true);
        $this->assertFalse($data['available']);
    }

    // -------------------------------------------------------------------------
    // checkEmail
    // -------------------------------------------------------------------------

    public function testCheckEmailReturnsTrueWhenAvailable(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['email' => 'new@test.com']);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('new@test.com')->willReturn(null);

        $response = $this->controller()->checkEmail($req);

        $data = json_decode($response->body(), true);
        $this->assertTrue($data['available']);
    }

    public function testCheckEmailReturnsFalseWhenTaken(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['email' => 'taken@test.com']);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('taken@test.com')->willReturn(['id' => 1]);

        $response = $this->controller()->checkEmail($req);

        $data = json_decode($response->body(), true);
        $this->assertFalse($data['available']);
    }

    public function testCheckEmailReturnsTrueForEmptyValue(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['email' => '']);
        $this->users->expects($this->never())->method('findByEmail');

        $response = $this->controller()->checkEmail($req);

        $data = json_decode($response->body(), true);
        $this->assertTrue($data['available']);
    }

    public function testCheckEmailAvailableWhenTakenByExcludedUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['email' => 'own@test.com', 'exclude_id' => '5']);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('own@test.com')->willReturn(['id' => 5]);

        $response = $this->controller()->checkEmail($req);

        $data = json_decode($response->body(), true);
        $this->assertTrue($data['available']);
    }

    public function testCheckEmailUnavailableWhenTakenByAnotherUser(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = $this->makeQueryRequest(['email' => 'other@test.com', 'exclude_id' => '5']);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('other@test.com')->willReturn(['id' => 9]);

        $response = $this->controller()->checkEmail($req);

        $data = json_decode($response->body(), true);
        $this->assertFalse($data['available']);
    }

    // -------------------------------------------------------------------------
    // setUserRate / deleteUserRate — accès multi-store (un type peut couvrir
    // plusieurs stores : l'accès est autorisé dès qu'on en gère au moins un)
    // -------------------------------------------------------------------------

    public function testSetUserRateForbiddenWhenManagingNoneOfTypeStores(): void
    {
        $this->userRates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 10]);
        $this->shiftTypes->method('findById')->willReturn(['id' => 7, 'code' => 'MORNING']);
        $this->shiftTypes->method('getStoreIds')->willReturn([2]);

        $req = $this->makePostRequest(['shift_type_id' => '7', 'hourly_rate' => '1500']);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => '10']);

        $this->userRates->expects($this->never())->method('save');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->setUserRate($req);
    }

    public function testSetUserRateAllowedWhenManagingOneOfTypeStores(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $this->userRates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->grantRequesterGlobalRole($this->roleAssignments, $this->roles);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10]);
        $this->shiftTypes->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7, 'code' => 'MORNING']);
        $this->shiftTypes->expects($this->atLeastOnce())->method('getStoreIds')->with(7)->willReturn([1, 2]);
        $this->userRates->method('findRate')->willReturn(null);

        $req = $this->makePostRequest(['shift_type_id' => '7', 'hourly_rate' => '1500']);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => '10']);

        $this->userRates->expects($this->once())->method('save')->willReturnCallback(fn (array $d) => $d + ['id' => 1]);

        $response = $this->controller()->setUserRate($req);

        $this->assertSame(302, $response->status());
    }

    public function testDeleteUserRateForbiddenWhenManagingNoneOfTypeStores(): void
    {
        $this->userRates = $this->createMock(UserShiftTypeRateRepositoryInterface::class);
        $this->userRates->method('findById')->willReturn(['id' => 3, 'user_id' => 10, 'shift_type_id' => 7]);
        $this->shiftTypes->method('findById')->willReturn(['id' => 7, 'code' => 'MORNING']);
        $this->shiftTypes->method('getStoreIds')->willReturn([2]);

        $req = $this->makePostRequest([]);
        $req->setAttribute('managed_store_ids', [1]);
        $req->setRouteParams(['id' => '10', 'rid' => '3']);

        $this->userRates->expects($this->never())->method('delete');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->deleteUserRate($req);
    }

    // -------------------------------------------------------------------------
    // Protection des Owners
    // -------------------------------------------------------------------------

    /** L'acteur (id 7) est Owner : la garde d'accès le laisse passer, c'est la garde Owner qui doit refuser. */
    private function actingAsOwner(int $targetId, array $post = []): Request
    {
        $this->roleAssignments->method('findByScope')->willReturn([
            ['id' => 1, 'user_id' => 7, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
            ['id' => 2, 'user_id' => $targetId, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $this->roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);

        $req = $this->makePostRequest($post);
        $req->setAttribute('auth_user', ['id' => 7, 'is_admin' => true]);
        $req->setRouteParams(['id' => (string) $targetId]);
        return $req;
    }

    public function testAnOwnerCannotDeleteThemselves(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 7, 'is_active' => 1, 'deleted_at' => null]);
        $this->users->expects($this->never())->method('delete');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->deleteUser($this->actingAsOwner(7));
    }

    public function testAnOwnerCanDeleteAnotherOwnerWhileOneRemains(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 8, 'is_active' => 1, 'deleted_at' => null]);
        // Anonymisation (audit M8) : la ligne n'est jamais effacée.
        $this->users->expects($this->never())->method('delete');
        $this->users->expects($this->once())->method('save')->with($this->callback(
            fn(array $d): bool => $d['id'] === 8 && $d['is_active'] === 0 && $d['deleted_at'] !== null
        ))->willReturnArgument(0);

        $response = $this->controller()->deleteUser($this->actingAsOwner(8));

        $this->assertSame(302, $response->status());
    }

    public function testAnOwnerCannotDeactivateThemselves(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 7, 'is_active' => 1, 'deleted_at' => null, 'email' => 'o@x.test']);
        $this->users->expects($this->never())->method('save');

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->updateUser($this->actingAsOwner(7, ['is_active' => '0']));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        Log::reset();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Accorde au demandeur (quel que soit son id) un rôle système global : la garde d'accès à l'employé ciblé le laisse passer. */
    private function grantRequesterGlobalRole($assignments, $roles): void
    {
        $assignments->method('findByUser')->willReturn([['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]]);
        $roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);
    }

    private function makePostRequest(array $post): Request
    {
        $_POST = $post;
        $req = new Request();
        // Demandeur par défaut ; les tests qui en ont besoin d'un autre le redéfinissent.
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => false]);
        return $req;
    }

    private function makeQueryRequest(array $query): Request
    {
        $_GET = $query;
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

    /** Audit M12 : sans is_admin ni is_active dans la requête, un Owner ne retire plus le statut Owner ni n'active/désactive. */
    public function testAnUpdateWithoutTheFlagsKeepsOwnerStatusAndActivity(): void
    {
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->users->method('findById')->willReturn([
            'id' => 8, 'is_active' => 1, 'deleted_at' => null, 'email' => 'o2@x.test',
            'first_name' => 'O', 'last_name' => 'Deux', 'furigana_last_name' => 'オ', 'furigana_first_name' => 'ツー', 'phone' => '090',
        ]);
        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved): array {
            $saved = $d;
            return $d;
        });
        $this->roleAssignments->expects($this->never())->method('revoke');

        $this->controller()->updateUser($this->actingAsOwner(8, ['first_name' => 'Owen']));

        $this->assertSame('Owen', $saved['first_name']);
        $this->assertSame(1, $saved['is_active']);
        $this->assertSame('090', $saved['phone']);
    }
}
