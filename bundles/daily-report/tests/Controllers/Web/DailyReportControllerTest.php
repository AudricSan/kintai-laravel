<?php

declare(strict_types=1);

namespace Tests\Bundles\DailyReport\Controllers\Web;


use kintai\Bundles\Installed\DailyReport\Controllers\Web\DailyReportController;
use App\Auth\PermissionService;
use App\Mail\MailerService;
use kintai\Bundles\Installed\DailyReport\Repositories\DailyReportRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportMailService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPdfService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPermissionService;
use App\Services\NotificationService;
use App\Services\TranslationService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Deux employés de magasins différents peuvent partager le même nom de famille.
 * Un shift mal assigné (import Excel, correction manuelle...) au mauvais user_id
 * ne doit jamais faire apparaître un staff d'un autre magasin dans la section
 * "shifts à venir / du lendemain" du formulaire de rapport journalier.
 */
final class DailyReportControllerTest extends TestCase
{
    private DailyReportRepositoryInterface&Stub $reports;
    private NotificationService&Stub $notifs;
    private ?DailyReportController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $this->notifs     = $this->createStub(NotificationService::class);

        $this->reports = $this->createStub(DailyReportRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): DailyReportController
    {
        if ($this->controller === null) {
            $translations = kintai_test_real_translator('en', false);
            $permissionService = new PermissionService();
            $permissions = new DailyReportPermissionService($permissionService);
            $pdfService = new DailyReportPdfService(
                kintai_test_views(sys_get_temp_dir()),
                $translations,
            );
            $mailService = new DailyReportMailService(
                $permissions,
                new MailerService(['default' => 'sendmail', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
                $translations,
                new \App\Services\UserSelection($permissionService),
            );
            $this->controller = new DailyReportController(
                kintai_test_views(sys_get_temp_dir()),
                $this->reports,
                $permissions,
                $pdfService,
                $mailService,
                new AuditLogger(),
                $translations,
                $this->notifs,
                $permissionService,
                new \App\Services\UserSelection($permissionService),
            );
        }

        return $this->controller;
    }

    public function testGetFormShiftRowsExcludesShiftsFromUsersNotMemberOfStore(): void
    {
        $storeId = 1;
        $date    = '2026-08-01';

        // Deux "Dupont" homonymes : #10 appartient au magasin 1, #20 appartient à un
        // autre magasin. Un import erroné a néanmoins créé un shift store_id=1 pour #20.
        kintai_test_shifts([
            ['user_id' => 10, 'shift_date' => '2026-08-01', 'shift_type_id' => null, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'duration_minutes' => 480, 'pause_minutes' => 60, 'deleted_at' => null],
            ['user_id' => 20, 'shift_date' => '2026-08-01', 'shift_type_id' => null, 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'duration_minutes' => 480, 'pause_minutes' => 60, 'deleted_at' => null],
        ]);
        kintai_test_shift_types([]);

        kintai_test_store_users(findMembership: fn(int $sid, int $uid) => ($sid === $storeId && $uid === 10) ? ['id' => 1, 'store_id' => $sid, 'user_id' => $uid] : null);
        kintai_test_users(findById: fn(int $id) => array_column([
            [10, ['id' => 10, 'last_name' => 'Dupont', 'first_name' => 'Jean']],
            [20, ['id' => 20, 'last_name' => 'Dupont', 'first_name' => 'Marie']],
        ], 1, 0)[$id] ?? null);
        $method = new \ReflectionMethod($this->controller(), 'getFormShiftRows');
        $method->setAccessible(true);
        $rows = $method->invoke($this->controller(), $storeId, $date);

        $this->assertCount(1, $rows, 'le shift du user #20 (non membre du magasin) doit être exclu');
        $this->assertSame('Dupont Jean', $rows[0]['employee']);
    }

    public function testSubmitNotifiesManagersWithApprovePermission(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $this->reports = $this->createMock(DailyReportRepositoryInterface::class);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Store A'] : null);
        $this->reports->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn([
            'id' => 10, 'store_id' => 1, 'status' => 'draft', 'author_id' => 9, 'report_date' => '2026-08-05',
        ]);
        $this->reports->method('save')->willReturnCallback(fn(array $d) => $d);
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [['user_id' => 9], ['user_id' => 20]] : [], findMembership: fn(int $s, int $u) => $s === 1 && $u === 9 ? ['store_id' => 1, 'user_id' => 9] : null);
        kintai_test_users(findById: fn(int $id) => array_column([
            [9, ['id' => 9]],
            [20, ['id' => 20]],
        ], 1, 0)[$id] ?? null);
        // Régression : soumettre son propre rapport n'est plus du libre-service, l'auteur a
        // donc ici besoin de daily_reports.submit (accordée seule, sans .approve) — voir
        // CHANGELOG.
        kintai_test_assignments(findByUser: fn(int $id) => array_column([
            [9, [['id' => 2, 'user_id' => 9, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1]]],
            [20, [['id' => 1, 'user_id' => 20, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]],
        ], 1, 0)[$id] ?? []);
        kintai_test_roles(findById: fn(int $id) => array_column([
            [2, ['id' => 2, 'is_system' => 0]],
            [3, ['id' => 3, 'is_system' => 0]],
        ], 1, 0)[$id] ?? null, getPermissions: fn(int $id) => array_column([
            [2, ['daily_reports.submit', 'daily_reports.approve']],
            [3, ['daily_reports.submit']],
        ], 1, 0)[$id] ?? []);
        $this->notifs->expects($this->once())->method('notifyMany')
            ->with([20], 'daily_report_submitted', $this->anything(), $this->anything(), 10);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->controller()->submit($req);
    }

    public function testValidateNotifiesAuthorButNotSelf(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $this->reports = $this->createMock(DailyReportRepositoryInterface::class);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Store A'] : null);
        $this->reports->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn([
            'id' => 10, 'store_id' => 1, 'status' => 'submitted', 'author_id' => 9, 'report_date' => '2026-08-05',
        ]);
        $this->reports->method('save')->willReturnCallback(fn(array $d) => $d);
        kintai_test_store_users(findMembership: fn() => ['store_id' => 1, 'user_id' => 20]);
        kintai_test_assignments(findByUser: fn(int $id) => $id === 20 ? [
            ['id' => 1, 'user_id' => 20, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['daily_reports.approve'] : []);
        $this->notifs->expects($this->once())->method('notify')
            ->with(9, 'daily_report_validated', $this->anything(), $this->anything(), 10);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 20]);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->controller()->validate($req);
    }

    /** Ouvrir un rapport qu'on a le droit de voir marque ses notifications comme lues. */
    public function testShowMarksTheReportNotificationsAsRead(): void
    {
        kintai_test_store_users(findMembership: fn() => ['store_id' => 1, 'user_id' => 20]);
        $this->notifs = $this->createMock(NotificationService::class);
        $this->reports = $this->createStub(DailyReportRepositoryInterface::class);
        kintai_test_stores(findById: fn() => ['id' => 1, 'name' => 'Store A']);
        $this->reports->method('findById')->willReturn([
            'id' => 10, 'store_id' => 1, 'status' => 'submitted', 'author_id' => 9, 'validated_by' => null, 'deleted_at' => null, 'report_date' => '2026-08-05',
        ]);
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => 20, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 2, 'is_system' => 0], getPermissions: fn() => ['daily_reports.view']);
        $this->notifs->expects($this->once())->method('markReadFor')->with(20, 'daily_report', 10);

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 20]);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        try {
            $this->controller()->show($req);
        } catch (\Throwable) {
            // Le rendu de la vue n'est pas l'objet du test : « lu » est marqué avant.
        }
    }

    /** Sans le droit de voir le rapport, rien n'est marqué lu. */
    public function testShowByAUserWhoCannotSeeTheReportMarksNothing(): void
    {
        kintai_test_store_users(findMembership: fn() => ['store_id' => 1, 'user_id' => 20]);
        $this->notifs = $this->createMock(NotificationService::class);
        $this->reports = $this->createStub(DailyReportRepositoryInterface::class);
        kintai_test_stores(findById: fn() => ['id' => 1, 'name' => 'Store A']);
        $this->reports->method('findById')->willReturn([
            'id' => 10, 'store_id' => 1, 'status' => 'submitted', 'author_id' => 9, 'report_date' => '2026-08-05',
        ]);

        $this->notifs->expects($this->never())->method('markReadFor');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 20]);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->show($req);
    }

    public function testValidateBySelfDoesNotSelfNotify(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $this->reports = $this->createMock(DailyReportRepositoryInterface::class);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'Store A'] : null);
        $this->reports->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn([
            'id' => 10, 'store_id' => 1, 'status' => 'submitted', 'author_id' => 9, 'report_date' => '2026-08-05',
        ]);
        $this->reports->method('save')->willReturnCallback(fn(array $d) => $d);
        kintai_test_store_users(findMembership: fn() => ['store_id' => 1, 'user_id' => 9]);
        kintai_test_assignments(findByUser: fn(int $id) => $id === 9 ? [
            ['id' => 1, 'user_id' => 9, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ] : []);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['daily_reports.approve'] : []);
        $this->notifs->expects($this->never())->method('notify');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9]);
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->controller()->validate($req);
    }
}
