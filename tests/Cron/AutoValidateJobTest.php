<?php

declare(strict_types=1);

namespace Tests\Bundles\DailyReport\Cron;

use PHPUnit\Framework\TestCase;
use App\Auth\PermissionService;
use kintai\Bundles\Installed\DailyReport\Cron\AutoValidateJob;
use App\Mail\MailerService;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportAutoValidateService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportMailService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPdfService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPermissionService;
use App\Services\TranslationService;
use kintai\Bundles\Installed\DailyReport\Repositories\DailyReportRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\ShiftRepositoryInterface;
use App\Repositories\ShiftTypeRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\TranslationRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\View\ViewRenderer;

final class AutoValidateJobTest extends TestCase
{
    private AutoValidateJob $job;

    protected function setUp(): void
    {
        $stores  = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findActive')->willReturn([]);
        $reports = $this->createStub(DailyReportRepositoryInterface::class);
        $users   = $this->createStub(UserRepositoryInterface::class);

        $shifts     = $this->createStub(ShiftRepositoryInterface::class);
        $shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $pdfService = new DailyReportPdfService(
            new ViewRenderer(dirname(__DIR__, 2) . '/Views'),
            new TranslationService(
                $this->createStub(TranslationRepositoryInterface::class),
                $this->createStub(LanguageRepositoryInterface::class),
            ),
            $shifts,
            $shiftTypes,
            $users,
        );

        $permissions = new DailyReportPermissionService(new PermissionService(
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
        ));
        $mailService = new DailyReportMailService(
            $permissions,
            new MailerService(['driver' => 'native', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
            new TranslationService(
                $this->createStub(TranslationRepositoryInterface::class),
                $this->createStub(LanguageRepositoryInterface::class),
            ),
            new \App\Services\UserSelection(
                $this->createStub(\App\Repositories\StoreUserRepositoryInterface::class),
                $users,
                new PermissionService($this->createStub(RoleAssignmentRepositoryInterface::class), $this->createStub(RoleRepositoryInterface::class)),
            ),
        );

        $logger          = new AuditLogger();

        $autoValidate = new DailyReportAutoValidateService($stores, $reports, $users, $permissions, $pdfService, $mailService);
        $this->job     = new AutoValidateJob($autoValidate, $logger);
    }

    // -------------------------------------------------------------------------
    // getName()
    // -------------------------------------------------------------------------

    public function testGetNameReturnsAutoValidate(): void
    {
        $this->assertSame('auto-validate', $this->job->getName());
    }

    // -------------------------------------------------------------------------
    // run()
    // -------------------------------------------------------------------------

    public function testRunWithDefaultDateUsesYesterday(): void
    {
        $response = $this->job->run($this->makeRequest());
        $data     = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['ok']);
        $this->assertSame(date('Y-m-d', strtotime('yesterday')), $data['date']);
        $this->assertSame(0, $data['validated']);
        $this->assertSame(0, $data['skipped']);
    }

    public function testRunWithCustomDate(): void
    {
        $response = $this->job->run($this->makeRequest(['date' => '2026-06-01']));
        $data     = json_decode($response->getContent(), true);

        $this->assertSame('2026-06-01', $data['date']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(array $query = []): Request
    {
        $queryStr = $query ? '?' . http_build_query($query) : '';
        $_SERVER  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/cron/auto-validate$queryStr", 'SCRIPT_NAME' => '/index.php'];
        $_GET     = $query;
        $_POST    = $_COOKIE = $_FILES = [];
        return Request::capture();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }
}
