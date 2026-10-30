<?php

declare(strict_types=1);

namespace Tests\Bundles\DailyReport\Console;

use PHPUnit\Framework\TestCase;
use App\Auth\PermissionService;
use kintai\Bundles\Installed\DailyReport\Console\AutoValidateCommand;
use App\Mail\MailerService;
use App\Services\AuditLogger;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportAutoValidateService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportMailService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPdfService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPermissionService;
use kintai\Bundles\Installed\DailyReport\Repositories\DailyReportRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class AutoValidateCommandTest extends TestCase
{
    private DailyReportAutoValidateService $autoValidate;

    protected function setUp(): void
    {
        kintai_test_stores(findActive: fn() => []);
        $reports = $this->createStub(DailyReportRepositoryInterface::class);

        $pdfService = new DailyReportPdfService(
            kintai_test_views(dirname(__DIR__, 2) . '/Views'),
            kintai_test_real_translator('en', false),
        );

        $permissions = new DailyReportPermissionService(new PermissionService(
        ));
        $mailService = new DailyReportMailService(
            $permissions,
            new MailerService(['default' => 'sendmail', 'from' => ['address' => 'test@kintai.test', 'name' => 'Kintai']]),
            kintai_test_real_translator('en', false),
            new \App\Services\UserSelection(
                new PermissionService(),
            ),
        );

        $autoValidate = new DailyReportAutoValidateService($reports, $permissions, $pdfService, $mailService);
        $this->autoValidate = $autoValidate;
    }

    /** @return array<string, mixed> sortie JSON de la commande */
    private function execute(array $input = []): array
    {
        app()->instance(DailyReportAutoValidateService::class, $this->autoValidate);
        app()->instance(\kintai\Contract\Services\AuditLogger::class, new AuditLogger());
        [$code, $output] = kintai_test_command(new AutoValidateCommand(), $input);
        $this->assertSame(0, $code);

        return json_decode($output, true);
    }

    public function testRunWithDefaultDateUsesYesterday(): void
    {
        $data = $this->execute();

        $this->assertTrue($data['ok']);
        $this->assertSame(date('Y-m-d', strtotime('yesterday')), $data['date']);
        $this->assertSame(0, $data['validated']);
        $this->assertSame(0, $data['skipped']);
    }

    public function testRunWithCustomDate(): void
    {
        $this->assertSame('2026-06-01', $this->execute(['--date' => '2026-06-01'])['date']);
    }

}
