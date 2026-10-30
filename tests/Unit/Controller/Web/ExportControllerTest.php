<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\PermissionService;
use Illuminate\Container\Container;
use App\Export\AvailabilitiesExport;
use App\Export\EmployeesExport;
use App\Export\ExportAccess;
use App\Export\ExportDirectory;
use App\Export\ExportWriter;
use App\Export\PayrollExport;
use App\Export\ShiftsExport;
use App\Export\ShiftTypesExport;
use App\Export\StoresExport;
use App\Models\Availability;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\IcalService;
use Illuminate\Support\Facades\Log;
use App\Services\ScheduleExportService;
use App\Services\ShiftWageCalculator;
use Illuminate\Translation\Translator;
use App\Services\UserSelection;
use App\Http\Controllers\ExportController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/** Page « Exports » : téléchargement de bout en bout pour un employé simple (ses données seulement). */
final class ExportControllerTest extends TestCase
{
    /** @var list<class-string> */
    private array $bound = [];
    private ExportController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): ?array => in_array($id, [5, 6], true)
            ? ['id' => $id, 'first_name' => $id === 5 ? 'Aki' : 'Ben', 'last_name' => 'Sato', 'email' => "u$id@example.test", 'language' => 'fr', 'is_active' => 1, 'password_hash' => 'x']
            : null);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturn(['id' => 3, 'code' => 'ST', 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo']);
        $stores->method('findActive')->willReturn([['id' => 3], ['id' => 4]]);
        kintai_test_store_users(findByStore: fn(int $id) => $id === 3 ? [['user_id' => 5], ['user_id' => 6]] : [], findByUser: fn(int $id): array => $id === 5 ? [['store_id' => 3]] : []);
        kintai_test_shifts([
            ['id' => 1, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'duration_minutes' => 480, 'shift_type_id' => 7],
            ['id' => 2, 'user_id' => 6, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '12:00', 'pause_minutes' => 0, 'duration_minutes' => 180, 'shift_type_id' => 7],
        ]);
        kintai_test_shifts([
            ['id' => 1, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'shift_type_id' => 7],
        ]);
        kintai_test_shift_types([['id' => 7, 'name' => 'Matin']]);
        kintai_test_assignments(findByUser: fn() => []);
        $permissions = new PermissionService();

        $directory = new ExportDirectory($users, $stores);
        $schedule  = new ScheduleExportService();
        $this->bind(ShiftsExport::class, new ShiftsExport($schedule, $directory));
        $this->bind(EmployeesExport::class, new EmployeesExport($directory));
        $this->bind(AvailabilitiesExport::class, new AvailabilitiesExport($directory));
        $this->bind(ShiftTypesExport::class, new ShiftTypesExport($directory));
        $this->bind(StoresExport::class, new StoresExport($directory));
        $this->bind(PayrollExport::class, new PayrollExport(new ShiftWageCalculator(), $directory));

        $translations = kintai_test_real_translator();

        $this->controller = new ExportController(
            kintai_test_views(dirname(__DIR__, 4) . '/resources/views'),
            new AuthService($users, $stores),
            new ExportAccess($permissions, $stores),
            new ExportWriter(),
            new UserSelection($users, $permissions),
            $stores,
            $schedule,
            new IcalService($translations),
            new AuditLogger(),
        );
        session()->put(auth()->getName(), 5);
    }

    protected function tearDown(): void
    {
        session()->forget([auth()->getName(), 'auth_pw_fp']);
        foreach ($this->bound as $class) {
            Container::getInstance()->offsetUnset($class);
        }
    }

    private function bind(string $class, object $instance): void
    {
        Container::getInstance()->instance($class, $instance);
        $this->bound[] = $class;
    }

    /** @param array<string, mixed> $query */
    private function request(array $query): Request
    {
        $_GET = $query;
        return Request::capture();
    }

    public function testAnEmployeeExportsOnlyHisOwnScheduleAsJson(): void
    {
        $response = $this->controller->download($this->request(['dataset' => 'shifts', 'target' => 'me', 'format' => 'json', 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(1, $data['count'], "l'horaire de l'autre employé n'est pas exporté");
        $this->assertSame('Sato Aki', $data['rows'][0]['employee']);
        $this->assertStringContainsString('kintai-shifts-2026-11-01_2026-11-30.json', $response->headers->get('Content-Disposition'));
    }

    public function testAskingForPeopleInThePersonalScopeStillGivesOnlyMyData(): void
    {
        $response = $this->controller->download($this->request(['dataset' => 'shifts', 'target' => 'me', 'format' => 'csv', 'user_ids' => ['6'], 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Sato Aki', $response->getContent());
        $this->assertStringNotContainsString('Sato Ben', $response->getContent());
    }

    public function testXlsxIsServedAsAWorkbook(): void
    {
        $response = $this->controller->download($this->request(['dataset' => 'employees', 'target' => 'me', 'format' => 'xlsx']));

        $this->assertSame(ExportWriter::XLSX_TYPE, $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $response->getContent(), 'un classeur xlsx est une archive zip');
    }

    public function testAnEmployeeCannotExportAStoreNorPayroll(): void
    {
        foreach ([['shifts', 'store:3'], ['shifts', 'all'], ['payroll', 'me'], ['stores', 'me']] as [$dataset, $target]) {
            $response = $this->controller->download($this->request(['dataset' => $dataset, 'target' => $target, 'format' => 'json']));

            $this->assertSame(302, $response->getStatusCode(), "$dataset/$target");
            $this->assertStringContainsString('/exports', $response->headers->get('Location'));
            $this->assertStringContainsString('forbidden', (string) session('error'));
        }
    }

    public function testTheCalendarFormatIsOnlyForTheScheduleOfOnePerson(): void
    {
        $ics = $this->controller->download($this->request(['dataset' => 'shifts', 'target' => 'me', 'format' => 'ics', 'from' => '2026-11-01', 'to' => '2026-11-30']));
        $this->assertStringContainsString('text/calendar', $ics->headers->get('Content-Type'));
        $this->assertSame(1, substr_count($ics->getContent(), 'BEGIN:VEVENT'));

        $other = $this->controller->download($this->request(['dataset' => 'employees', 'target' => 'me', 'format' => 'ics']));
        $this->assertSame(302, $other->getStatusCode());
        $this->assertNull(session('error'));
    }

    public function testUnknownDatasetOrFormatGoesBackToThePage(): void
    {
        foreach ([['nope', 'json'], ['shifts', 'xml']] as [$dataset, $format]) {
            $response = $this->controller->download($this->request(['dataset' => $dataset, 'target' => 'me', 'format' => $format]));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringEndsWith('/exports', $response->headers->get('Location'));
        }
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        session()->forget(auth()->getName());
        $response = $this->controller->download($this->request(['dataset' => 'shifts', 'target' => 'me', 'format' => 'json']));

        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }
}
