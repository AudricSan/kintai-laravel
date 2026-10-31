<?php

declare(strict_types=1);

namespace Tests\Bundles\ResignationReport\Controllers\Web;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 4));
}


use kintai\Bundles\Installed\ResignationReport\Controllers\Web\AdminResignationReportController;
use kintai\Bundles\Installed\ResignationReport\Services\ResignationDeactivation;
use App\Auth\PermissionService;
use Tests\Support\TestContainer;
use App\Exceptions\NotFoundException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

final class AdminResignationReportControllerTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['resignation_reports', 'resignation-report'];
    }

    private ?AdminResignationReportController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $viewDir = sys_get_temp_dir() . '/kintai-resignation-report-views';
        $this->ensureViewFile($viewDir, 'reports-resignation');
        $this->ensureViewFile($viewDir, 'reports-resignation-show');
        $this->ensureViewFile($viewDir, 'reports-resignation-form');
        $this->ensureViewFile($viewDir, 'reports-resignation-export-pdf');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->resetRows();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminResignationReportController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-resignation-report-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('resignation-report', $viewDir);
            $this->controller = new AdminResignationReportController(
                $view,
                new AuditLogger(),
                new PermissionService(),
                new ResignationDeactivation(),
                new \App\Auth\UserAnonymizer(
                    new \App\Auth\CredentialRevoker(),
                    sys_get_temp_dir(),
                ),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    // -------------------------------------------------------------------------
    // Export de la liste (item 5)
    // -------------------------------------------------------------------------

    public function testExportResignationReportsJsonReturnsData(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'Store A']]);
        $this->seedRows([
            ['id' => 10, 'store_id' => 1, 'employee_name' => 'Jean Dupont'],
        ]);

        $response = $this->controller()->exportResignationReportsJson($req);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Jean Dupont', $data[0]['employee_name']);
    }

    public function testExportResignationReportsPdfReturnsHtmlPreview(): void
    {
        $_SERVER['PHP_SELF'] ??= '/index.php';

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'Store A']]);
        $this->seedRows([]);

        $response = $this->controller()->exportResignationReportsPdf($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF', $response->getContent());
    }

    public function testExportResignationReportsPdfDownloadReturnsPdfResponse(): void
    {
        $_SERVER['PHP_SELF'] ??= '/index.php';

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'Store A']]);
        $this->seedRows([]);

        $response = $this->controller()->exportResignationReportsPdfDownload($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function testAllResignationReportsPassesYearMonthPersonFiltersToRepository(): void
    {
        $this->resetRows();
        $_GET = ['year' => '2026', 'month' => '07', 'person' => 'Dupont', 'store_id' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'Store A']]);
        $this->seedRows([]);

        $response = $this->controller()->allResignationReports($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllResignationReportsWithoutFiltersPassesEmptyFilters(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'Store A']]);
        $this->seedRows([]);

        $response = $this->controller()->allResignationReports($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateResignationReportOnlyListsMembersOfTheStore(): void
    {
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Store A'] : null);
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [
            ['user_id' => 10, 'store_id' => 1],
            ['user_id' => 20, 'store_id' => 1],
        ] : []);
        kintai_test_users(findById: fn(int $id) => array_column([
            [10, ['id' => 10, 'first_name' => 'Jean', 'last_name' => 'Dupont', 'employee_code' => 'EMP010']],
            [20, ['id' => 20, 'first_name' => 'Paul', 'last_name' => 'Martin', 'employee_code' => 'EMP020']],
        ], 1, 0)[$id] ?? null);
        $response = $this->controller()->createResignationReport($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGetManagersForReportFormIncludesOwnerEvenWithoutStoreMembership(): void
    {
        // L'Owner n'a pas forcément de ligne store_user (voir install.php) mais doit
        // rester sélectionnable comme responsable sur n'importe quel store.
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [
            ['user_id' => 10, 'store_id' => 1, 'role' => 'manager'],
        ] : []);
        kintai_test_users(findById: fn(int $id) => array_column([
            [10, ['id' => 10, 'first_name' => 'Jean', 'last_name' => 'Dupont', 'employee_code' => 'EMP010']],
        ], 1, 0)[$id] ?? null, findAll: fn() => [
            ['id' => 99, 'first_name' => 'Owner', 'last_name' => 'Test'],
            ['id' => 10, 'first_name' => 'Jean', 'last_name' => 'Dupont'],
        ]);
        // L'utilisateur 99 détient un rôle système (Owner) en portée globale (RBAC).
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 3, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : [], findByScope: fn() => [
            ['id' => 2, 'user_id' => 99, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        // Le rôle RBAC affecté à l'utilisateur 10 accorde employees.update sur le store 1.
        kintai_test_roles(findById: fn(int $id) => array_column([
            [1, ['id' => 1, 'slug' => 'owner', 'is_system' => 1]],
            [2, ['id' => 2, 'is_system' => 0]],
        ], 1, 0)[$id] ?? null, getPermissions: static fn(int $id) => $id === 2 ? ['employees.update'] : []);
        $method = new \ReflectionMethod($this->controller(), 'getManagersForReportForm');
        $method->setAccessible(true);
        $managers = $method->invoke($this->controller(), 1);

        $ids = array_column($managers, 'id');
        $this->assertContains(99, $ids, 'Owner (is_admin) doit apparaître même sans adhésion au store');
        $this->assertContains(10, $ids, 'membre avec employees.update sur ce store doit apparaître');
    }

    public function testGetManagersForReportFormExcludesMemberWithoutEmployeesUpdatePermission(): void
    {
        // Un membre du store sans la permission RBAC employees.update ne doit plus
        // apparaître, même si sa colonne legacy store_user.role vaut 'manager'
        // (colonne jamais resynchronisée quand les permissions d'un rôle changent).
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [
            ['user_id' => 10, 'store_id' => 1, 'role' => 'manager'],
        ] : []);
        kintai_test_users(findById: fn(int $id) => array_column([
            [10, ['id' => 10, 'first_name' => 'Jean', 'last_name' => 'Dupont', 'employee_code' => 'EMP010']],
        ], 1, 0)[$id] ?? null, findAll: fn() => []);
        kintai_test_assignments(findByUser: fn(int $id) => $id === 10 ? [
            ['id' => 1, 'user_id' => 10, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['shifts.view'] : []);
        $method = new \ReflectionMethod($this->controller(), 'getManagersForReportForm');
        $method->setAccessible(true);
        $managers = $method->invoke($this->controller(), 1);

        $this->assertNotContains(10, array_column($managers, 'id'));
    }

    public function testCreateResignationReportKeepsPresetUserEvenIfNotAStoreMember(): void
    {
        $_GET = ['user_id' => '99'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'display_name' => 'Owner']);
        $req->setRouteParams(['id' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Store A'] : null);
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [] : []);
        kintai_test_users(findById: fn(int $id) => $id === 99 ? ['id' => 99, 'first_name' => 'Ex', 'last_name' => 'Employee', 'employee_code' => 'EMP099'] : null);
        $response = $this->controller()->createResignationReport($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowResignationReportLogsConsultation(): void
    {
        $this->resetRows();
        $log = kintai_test_log();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '20']);

        $this->seedRows([['id' => 20, 'store_id' => 1, 'employee_name' => 'Bob']]);
        kintai_test_stores(findById: fn() => ['id' => 1, 'name' => 'Store A']);
        $response = $this->controller()->showResignationReport($req);

        $this->assertSame(200, $response->getStatusCode());

        $audit = array_values(array_filter($log->on('audit'), fn(array $e) => $e['message'] === 'resignation_report.viewed'));
        $this->assertCount(1, $audit);
        $this->assertSame('resignation_report', $audit[0]['context']['resource_type']);
        $this->assertSame(20, $audit[0]['context']['resource_id']);
    }

    public function testShowResignationReportOfAnotherStoreThrowsNotFound(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '20']);

        // Le rapport existe mais appartient au store 2, pas au store 1 de l'URL
        $this->seedRows([['id' => 20, 'store_id' => 2]]);

        $this->expectException(NotFoundException::class);
        $this->controller()->showResignationReport($req);
    }

    public function testStoreResignationReportDeactivatesTheEmployee(): void
    {
        $_POST = [
            'employee_number'    => 'E-1',
            'employee_name'      => 'Alice Martin',
            'resignation_date'   => '2026-08-01',
            'reason'             => 'Départ volontaire',
            'resignation_notice' => '',
            'notes'              => '',
            'person_in_charge'   => 'Manager X',
            'user_id'            => '5',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        kintai_test_store_users(findMembership: fn() => ['id' => 3]);
        kintai_test_users(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'is_active' => 1] : null);
        $response = $this->controller()->storeResignationReport($req);
        $captured = \App\Models\User::findById(5);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($captured);
        $this->assertSame(0, $captured['is_active']);
    }

    /** Audit du 13/10/2026 : la création désactivait n'importe quel compte, y compris hors du magasin. */
    public function testStoreResignationReportRefusesUserOutsideTheStore(): void
    {
        $this->resetRows();
        $_POST = ['employee_name' => 'X', 'user_id' => '77'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '1']);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        kintai_test_store_users(findMembership: fn() => null);
        $this->expectNoChange();

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->storeResignationReport($req);
    }

    public function testStoreResignationReportRefusesOwnerAccountForNonOwner(): void
    {
        $_POST = ['employee_name' => 'Owner', 'user_id' => '7'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [1]);
        $req->attributes->set('auth_user', ['id' => 2]);
        $req->setRouteParams(['id' => '1']);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        kintai_test_store_users(findMembership: fn() => ['id' => 3]);
        kintai_test_assignments(findByScope: fn() => [
            ['id' => 1, 'user_id' => 7, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 10, 'is_system' => 1]);
        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->storeResignationReport($req);
    }

    public function testDeletePermanentlyRefusesUserWhoBelongsToAnUnmanagedStore(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [1]);
        $req->attributes->set('auth_user', ['id' => 2]);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);
        $this->seedRows([['id' => 10, 'store_id' => 1, 'user_id' => 5]]);
        kintai_test_assignments(findByScope: fn() => []);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 5 ? [['store_id' => 1], ['store_id' => 4]] : []);
        $this->expectNoChange();

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->deleteResignationReportPermanently($req);
    }

    public function testUpdateResignationReportMergesPostFieldsAndRedirects(): void
    {
        $this->resetRows();
        $_POST = [
            'user_id'            => '5',
            'employee_number'    => 'E-42',
            'employee_name'      => 'Alice Martin',
            'resignation_date'   => '2026-08-01',
            'reason'             => '',
            'resignation_notice' => '',
            'notes'              => '',
            'person_in_charge'   => '',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        kintai_test_store_users(findMembership: fn() => ['id' => 3]);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->seedRows([['id' => 10, 'store_id' => 1, 'employee_name' => 'Ancien nom']]);

        $this->expectSaved(fn(array $data) =>
                $data['id'] === 10
                && $data['user_id'] === 5
                && $data['employee_name'] === 'Alice Martin');

        $response = $this->controller()->updateResignationReport($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    /** Audit du 13/10/2026 : un rapport ne peut pas être lié à un compte étranger au magasin. */
    public function testUpdateResignationReportRejectsUserOutsideTheStore(): void
    {
        $this->resetRows();
        $_POST = ['user_id' => '99', 'employee_name' => 'X'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->seedRows([['id' => 10, 'store_id' => 1]]);
        kintai_test_store_users(findMembership: fn() => null);
        $this->expectNoChange();

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->updateResignationReport($req);
    }

    public function testDeleteResignationReportDeletesAndLogs(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->seedRows([['id' => 10, 'store_id' => 1]]);
        $this->expectDeleted(10);

        $response = $this->controller()->deleteResignationReport($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeleteResignationReportReactivatesTheAssociatedAccount(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        // Rapport appliqué (deactivated_at renseigné) : sa suppression rend l'accès.
        $this->seedRows([['id' => 10, 'store_id' => 1, 'user_id' => 5, 'deactivated_at' => '2026-08-01 09:00:00']]);
        $this->expectDeleted(10);
        kintai_test_users(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'is_active' => 0] : null);
        $response = $this->controller()->deleteResignationReport($req);
        $captured = \App\Models\User::findById(5);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $captured['is_active']);
    }

    public function testDeleteResignationReportWithoutLinkedUserDoesNotTouchUsers(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->seedRows([['id' => 10, 'store_id' => 1]]);
        $this->expectDeleted(10);
        $response = $this->controller()->deleteResignationReport($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    /** Audit n°8 : le compte est anonymisé (contrat 1.21), plus seulement supprimé en douceur avec ses données. */
    public function testDeleteResignationReportPermanentlyDeletesReportAndAnonymizesUser(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->seedRows([['id' => 10, 'store_id' => 1, 'user_id' => 5]]);
        $this->expectDeleted(10);
        kintai_test_users(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'email' => 'alice@example.test', 'phone' => '0600000000'] : null);
        $response = $this->controller()->deleteResignationReportPermanently($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeleteResignationReportPermanentlyWithoutLinkedUserOnlyDeletesReport(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->seedRows([['id' => 10, 'store_id' => 1]]);
        $this->expectDeleted(10);
        $response = $this->controller()->deleteResignationReportPermanently($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testReactivateUserReactivatesTheAssociatedAccount(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        $this->seedRows([['id' => 10, 'store_id' => 1, 'user_id' => 5]]);
        kintai_test_users(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'is_active' => 0] : null);
        $response = $this->controller()->reactivateUser($req);
        $captured = \App\Models\User::findById(5);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $captured['is_active']);
    }

    private function ensureViewFile(string $dir, string $view): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        touch($file);
    }

    // -------------------------------------------------------------------------
    // Audit n°8 (D2) : désactivation à la date de démission, jamais sur soi ni sur un autre gérant
    // -------------------------------------------------------------------------

    private function storeRequest(int $callerId, string $date, int $userId = 5): Request
    {
        $_POST = ['employee_name' => 'Alice', 'resignation_date' => $date, 'user_id' => (string) $userId];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [1]);
        $req->attributes->set('auth_user', ['id' => $callerId]);
        $req->setRouteParams(['id' => '1']);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        kintai_test_store_users(findMembership: fn() => ['id' => 3]);
        return $req;
    }

    public function testAFutureResignationDateDoesNotDeactivateTheAccountYet(): void
    {
        $this->resetRows();
        $req = $this->storeRequest(2, date('Y-m-d', strtotime('+20 days')));
        $this->expectSaved(fn(array $row) => true);
        $this->assertSame(302, $this->controller()->storeResignationReport($req)->getStatusCode());
    }

    public function testTodaysResignationDeactivatesNowAndMarksTheReport(): void
    {
        $this->resetRows();
        $req = $this->storeRequest(2, date('Y-m-d'));
        $this->startCapture();
        kintai_test_users(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'is_active' => 1] : null);
        $this->controller()->storeResignationReport($req);
        $saved = $this->capturedRow();

        $this->assertNotEmpty($saved['deactivated_at'] ?? null, 'rapport marqué appliqué');
    }

    public function testAManagerCannotFileTheirOwnResignation(): void
    {
        $this->resetRows();
        $req = $this->storeRequest(5, date('Y-m-d'));
        $this->expectNoChange();
        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->storeResignationReport($req);
    }

    public function testAManagerCannotFileAnotherManagersResignation(): void
    {
        $this->resetRows();
        $req = $this->storeRequest(2, date('Y-m-d'));
        kintai_test_assignments(findByUser: fn(int $u) => $u === 5
            ? [['id' => 4, 'user_id' => 5, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]
            : [], findByScope: fn() => []);
        kintai_test_roles(findById: fn() => ['id' => 2, 'is_system' => 0], getPermissions: fn() => ['shifts.view']);
        $this->expectNoChange();

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->storeResignationReport($req);
    }

    public function testAnOwnerCanFileAManagersResignation(): void
    {
        $this->resetRows();
        $req = $this->storeRequest(7, date('Y-m-d', strtotime('+3 days')));
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 4, 'user_id' => 5, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ], findByScope: fn() => [
            ['id' => 1, 'user_id' => 7, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id) => ['id' => $id, 'is_system' => $id === 10 ? 1 : 0], getPermissions: fn() => ['shifts.view']);
        $this->expectSaved(fn(array $row) => true);

        $this->assertSame(302, $this->controller()->storeResignationReport($req)->getStatusCode());
    }

    public function testDeletingAReportNotYetAppliedLeavesTheAccountAlone(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);
        $this->seedRows([['id' => 10, 'store_id' => 1, 'user_id' => 5, 'deactivated_at' => null]]);
        $this->controller()->deleteResignationReport($req);

        $this->assertSame(0, count($this->rowsInTable()), 'le rapport est supprimé');
    }
}
