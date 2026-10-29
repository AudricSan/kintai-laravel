<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Export\AvailabilitiesExport;
use kintai\Core\Export\EmployeesExport;
use kintai\Core\Export\ExportAccess;
use kintai\Core\Export\ExportDirectory;
use kintai\Core\Export\ExportWriter;
use kintai\Core\Export\PayrollExport;
use kintai\Core\Export\ShiftsExport;
use kintai\Core\Export\ShiftTypesExport;
use kintai\Core\Export\StoresExport;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\JsonLanguageRepository;
use kintai\Core\Repositories\JsonTranslationRepository;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use Illuminate\Http\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\IcalService;
use kintai\Core\Services\Log;
use kintai\Core\Services\ScheduleExportService;
use kintai\Core\Services\ShiftWageCalculator;
use kintai\Core\Services\TranslationService;
use kintai\Core\Services\UserSelection;
use kintai\UI\Controller\Web\ExportController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/** Page « Exports » : téléchargement de bout en bout pour un employé simple (ses données seulement). */
final class ExportControllerTest extends TestCase
{
    /** @var list<class-string> */
    private array $bound = [];
    private ExportController $controller;

    protected function setUp(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): ?array => in_array($id, [5, 6], true)
            ? ['id' => $id, 'first_name' => $id === 5 ? 'Aki' : 'Ben', 'last_name' => 'Sato', 'email' => "u$id@example.test", 'language' => 'fr', 'is_active' => 1, 'password_hash' => 'x']
            : null);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturn(['id' => 3, 'code' => 'ST', 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo']);
        $stores->method('findActive')->willReturn([['id' => 3], ['id' => 4]]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByUser')->willReturnCallback(fn(int $id): array => $id === 5 ? [['store_id' => 3]] : []);
        $storeUsers->method('findByStore')->willReturn([['user_id' => 5], ['user_id' => 6]]);
        $shifts = $this->createStub(ShiftRepositoryInterface::class);
        $shifts->method('findByStoreBetween')->willReturn([
            ['id' => 1, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'duration_minutes' => 480, 'shift_type_id' => 7],
            ['id' => 2, 'user_id' => 6, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '12:00', 'pause_minutes' => 0, 'duration_minutes' => 180, 'shift_type_id' => 7],
        ]);
        $shifts->method('findByUser')->willReturn([
            ['id' => 1, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'shift_type_id' => 7],
        ]);
        $types = $this->createStub(ShiftTypeRepositoryInterface::class);
        $types->method('findAll')->willReturn([['id' => 7, 'name' => 'Matin']]);

        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn([]);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $permissions = new PermissionService($assignments, $roles);

        $directory = new ExportDirectory($users, $stores);
        $schedule  = new ScheduleExportService();
        $this->bind(ShiftsExport::class, new ShiftsExport($shifts, $types, $schedule, $directory));
        $this->bind(EmployeesExport::class, new EmployeesExport($storeUsers, $directory));
        $this->bind(AvailabilitiesExport::class, new AvailabilitiesExport($this->createStub(AvailabilityRepositoryInterface::class), $directory));
        $this->bind(ShiftTypesExport::class, new ShiftTypesExport($types, $directory));
        $this->bind(StoresExport::class, new StoresExport($directory));
        $this->bind(PayrollExport::class, new PayrollExport($shifts, $types, $this->createStub(UserShiftTypeRateRepositoryInterface::class), new ShiftWageCalculator(), $directory));

        $translations = new TranslationService(
            new JsonTranslationRepository(dirname(__DIR__, 4) . '/lang', dirname(__DIR__, 4) . '/app/Bundles'),
            new JsonLanguageRepository(dirname(__DIR__, 4) . '/lang'),
        );
        $this->bind(TranslationService::class, $translations);

        $this->controller = new ExportController(
            new ViewRenderer(dirname(__DIR__, 4) . '/app/UI/View'),
            new AuthService($users, $storeUsers, $stores, $roles, $assignments, $this->createStub(RememberTokenRepositoryInterface::class)),
            new ExportAccess($permissions, $stores, $storeUsers),
            new ExportWriter(),
            new UserSelection($storeUsers, $users, $permissions),
            $stores,
            $shifts,
            $types,
            $schedule,
            new IcalService($translations),
            new AuditLogger(),
        );
        session()->put('auth_user_id', 5);
    }

    protected function tearDown(): void
    {
        session()->forget(['auth_user_id', 'auth_pw_fp']);
        Log::reset();
        foreach ($this->bound as $class) {
            Container::getInstance()->forget($class);
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
            $this->assertStringContainsString('/exports?error=forbidden', $response->headers->get('Location'));
        }
    }

    public function testTheCalendarFormatIsOnlyForTheScheduleOfOnePerson(): void
    {
        $ics = $this->controller->download($this->request(['dataset' => 'shifts', 'target' => 'me', 'format' => 'ics', 'from' => '2026-11-01', 'to' => '2026-11-30']));
        $this->assertStringContainsString('text/calendar', $ics->headers->get('Content-Type'));
        $this->assertSame(1, substr_count($ics->getContent(), 'BEGIN:VEVENT'));

        $other = $this->controller->download($this->request(['dataset' => 'employees', 'target' => 'me', 'format' => 'ics']));
        $this->assertSame(302, $other->getStatusCode());
        $this->assertStringNotContainsString('error=', $other->headers->get('Location'));
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
        session()->forget('auth_user_id');
        $response = $this->controller->download($this->request(['dataset' => 'shifts', 'target' => 'me', 'format' => 'json']));

        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }
}
