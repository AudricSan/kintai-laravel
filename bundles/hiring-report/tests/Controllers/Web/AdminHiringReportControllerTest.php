<?php

declare(strict_types=1);

namespace Tests\Bundles\HiringReport\Controllers\Web;


use kintai\Bundles\Installed\HiringReport\Controllers\Web\AdminHiringReportController;
use Tests\Support\TestContainer;
use App\Exceptions\NotFoundException;
use kintai\Bundles\Installed\HiringReport\Repositories\HiringReportRepositoryInterface;
use App\Models\Store;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminHiringReportControllerTest extends TestCase
{
    private HiringReportRepositoryInterface&Stub $hiringReports;
    private UserRepositoryInterface&Stub $users;
    private ?AdminHiringReportController $controller = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $viewDir = sys_get_temp_dir() . '/kintai-hiring-report-views';
        $this->ensureViewFile($viewDir, 'reports-hiring');
        $this->ensureViewFile($viewDir, 'reports-hiring-show');
        $this->ensureViewFile($viewDir, 'reports-hiring-form');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->hiringReports = $this->createStub(HiringReportRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminHiringReportController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-hiring-report-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('hiring-report', $viewDir);
            $this->controller = new AdminHiringReportController(
                $view,
                $this->users,
                $this->hiringReports,
                new AuditLogger(),
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

    public function testAllHiringReportsListsAcrossStoresForAdmin(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->attributes->set('managed_store_ids', null);

        kintai_test_stores(findAll: fn() => [
            ['id' => 1, 'name' => 'Store A'],
            ['id' => 2, 'name' => 'Store B'],
        ]);
        $this->hiringReports->expects($this->once())->method('findAll')
            ->with([], [])
            ->willReturn([
                ['id' => 10, 'store_id' => 1, 'employee_name' => 'Alice'],
                ['id' => 11, 'store_id' => 2, 'employee_name' => 'Bob'],
            ]);

        $response = $this->controller()->allHiringReports($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllHiringReportsAppliesStoreYearMonthFilters(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $_GET = ['store_id' => '2', 'year' => '2026', 'month' => '08'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->attributes->set('managed_store_ids', null);

        kintai_test_stores(findAll: fn() => [
            ['id' => 1, 'name' => 'Store A'],
            ['id' => 2, 'name' => 'Store B'],
        ]);
        $this->hiringReports->expects($this->once())->method('findAll')
            ->with([2], ['store_id' => 2, 'year' => '2026', 'month' => '08'])
            ->willReturn([]);

        $response = $this->controller()->allHiringReports($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllHiringReportsScopesNonAdminToManagedStores(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->attributes->set('managed_store_ids', [3]);

        kintai_test_stores(findById: fn(int $id) => $id === 3 ? ['id' => 3, 'name' => 'Store C'] : null);
        $this->hiringReports->expects($this->once())->method('findAll')
            ->with([3], [])
            ->willReturn([]);

        $response = $this->controller()->allHiringReports($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowHiringReportLogsConsultation(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $log = kintai_test_log();
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->hiringReports->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10, 'store_id' => 1, 'employee_name' => 'Alice']);
        kintai_test_stores(findById: fn() => ['id' => 1, 'name' => 'Store A']);
        $response = $this->controller()->showHiringReport($req);

        $this->assertSame(200, $response->getStatusCode());

        $audit = array_values(array_filter($log->on('audit'), fn(array $e) => $e['message'] === 'hiring_report.viewed'));
        $this->assertCount(1, $audit);
        $this->assertSame('hiring_report', $audit[0]['context']['resource_type']);
        $this->assertSame(10, $audit[0]['context']['resource_id']);
    }

    public function testShowHiringReportOfAnotherStoreThrowsNotFound(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        // Le rapport existe mais appartient au store 2, pas au store 1 de l'URL
        $this->hiringReports->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10, 'store_id' => 2]);

        $this->expectException(NotFoundException::class);
        $this->controller()->showHiringReport($req);
    }

    /** Audit du 13/10/2026 : un rapport d'embauche ne peut pas être lié à un compte étranger au magasin. */
    public function testStoreHiringReportRefusesUserOutsideTheStore(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $_POST = ['user_id' => '77', 'employee_name' => 'X'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '1']);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        kintai_test_store_users(findMembership: fn() => null);
        $this->hiringReports->expects($this->never())->method('save');

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->storeHiringReport($req);
    }

    /** Le formulaire ne propose que les membres du magasin, pas tous les comptes de l'instance. */
    public function testCreateHiringReportOnlyListsMembersOfTheStore(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => '1']);
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'name' => 'A'] : null);
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [['user_id' => 5]] : []);
        $this->users->expects($this->never())->method('findAll');
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5]);

        $this->assertSame(200, $this->controller()->createHiringReport($req)->getStatusCode());
    }

    public function testStoreHiringReportSavesAndRedirects(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $_POST = [
            'user_id'             => '5',
            'employee_number'     => 'E-1',
            'employee_name'       => 'Alice Martin',
            'furigana_last_name'  => '',
            'furigana_first_name' => '',
            'gender'              => '',
            'tax_classification'  => '',
            'birth_date'          => '',
            'hire_date'           => '2026-08-01',
            'education'           => '',
            'postal_code'         => '',
            'address'             => '',
            'phone'               => '',
            'mobile_phone'        => '',
            'email'               => '',
            'guarantor_name'      => '',
            'guarantor_phone'     => '',
            'store_name'          => '',
            'hired_by'            => 'Manager X',
            'notes'               => '',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => '1']);

        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1] : null);
        kintai_test_store_users(findMembership: fn() => ['id' => 3]);
        $this->hiringReports->expects($this->once())->method('save')->with($this->callback(
            fn(array $data) =>
                $data['store_id'] === 1
                && $data['user_id'] === 5
                && $data['employee_name'] === 'Alice Martin'
        ))->willReturn(['id' => 99]);

        $response = $this->controller()->storeHiringReport($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateHiringReportMergesPostFieldsAndRedirects(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $_POST = [
            'user_id'         => '5',
            'employee_number' => 'E-42',
            'employee_name'   => 'Alice Martin',
            'notes'           => '',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        kintai_test_store_users(findMembership: fn() => ['id' => 3]);
        $this->hiringReports->expects($this->atLeastOnce())->method('findById')->with(10)
            ->willReturn(['id' => 10, 'store_id' => 1, 'employee_name' => 'Ancien nom']);

        $this->hiringReports->expects($this->once())->method('save')->with($this->callback(
            fn(array $data) =>
                $data['id'] === 10
                && $data['user_id'] === 5
                && $data['employee_number'] === 'E-42'
                && $data['employee_name'] === 'Alice Martin'
                && $data['notes'] === null // champ vide → null
        ));

        $response = $this->controller()->updateHiringReport($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeleteHiringReportDeletesAndLogs(): void
    {
        $this->hiringReports = $this->createMock(HiringReportRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '1', 'rid' => '10']);

        $this->hiringReports->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10, 'store_id' => 1]);
        $this->hiringReports->expects($this->once())->method('delete')->with(10);

        $response = $this->controller()->deleteHiringReport($req);

        $this->assertSame(302, $response->getStatusCode());
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
}
