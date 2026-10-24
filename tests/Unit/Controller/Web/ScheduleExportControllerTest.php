<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Container;
use kintai\Core\Exceptions\NotFoundException;
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
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\IcalService;
use kintai\Core\Services\Log;
use kintai\Core\Services\ScheduleExportService;
use kintai\Core\Services\TranslationService;
use kintai\UI\Controller\Web\ScheduleExportController;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/** Téléchargement de son propre horaire : JSON, CSV, ICS, réservé aux membres du magasin demandé. */
final class ScheduleExportControllerTest extends TestCase
{
    private ShiftRepositoryInterface&Stub $shifts;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ScheduleExportController $controller;

    protected function setUp(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn($id) => $id === 9 ? ['id' => 9, 'is_active' => 1, 'first_name' => 'Aki', 'last_name' => 'Sato', 'language' => 'fr', 'password_hash' => 'x'] : null);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        // Membre du magasin 3 uniquement.
        $this->storeUsers->method('findMembership')->willReturnCallback(fn($store, $user) => $store === 3 && $user === 9 ? ['id' => 1] : null);

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturnCallback(fn($id) => in_array($id, [3, 4], true) ? ['id' => $id, 'code' => 'ST' . $id, 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo'] : null);

        $this->shifts = $this->createStub(ShiftRepositoryInterface::class);
        $this->shifts->method('findByUser')->willReturn([
            ['id' => 1, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'shift_type_id' => 7],
            ['id' => 2, 'store_id' => 4, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 0, 'shift_type_id' => 7],
            ['id' => 3, 'store_id' => 3, 'shift_date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 0, 'shift_type_id' => 7],
        ]);
        $types = $this->createStub(ShiftTypeRepositoryInterface::class);
        $types->method('findAll')->willReturn([['id' => 7, 'name' => 'Matin']]);

        $translations = new TranslationService(
            new JsonTranslationRepository(dirname(__DIR__, 4) . '/lang', dirname(__DIR__, 4) . '/app/Bundles'),
            new JsonLanguageRepository(dirname(__DIR__, 4) . '/lang'),
        );
        Container::getInstance()->instance(TranslationService::class, $translations);

        $this->controller = new ScheduleExportController(
            new AuthService(
                $users,
                $this->storeUsers,
                $stores,
                $this->createStub(RoleRepositoryInterface::class),
                $this->createStub(RoleAssignmentRepositoryInterface::class),
                $this->createStub(RememberTokenRepositoryInterface::class),
            ),
            $this->shifts,
            $types,
            $stores,
            $this->storeUsers,
            new ScheduleExportService(),
            new IcalService($translations),
            new AuditLogger(),
        );
        $_SESSION['auth_user_id'] = 9;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id'], $_SESSION['auth_pw_fp']);
        Log::reset();
        $instances = new \ReflectionProperty(Container::class, 'instances');
        $instances->setAccessible(true);
        $bound = $instances->getValue(Container::getInstance());
        unset($bound[TranslationService::class]);
        $instances->setValue(Container::getInstance(), $bound);
    }

    /** @param array<string, string> $query */
    private function request(array $query): Request
    {
        $_GET = $query;
        return new Request();
    }

    public function testJsonContainsOnlyMyShiftsOfTheStoreAndThePeriod(): void
    {
        $response = $this->controller->download($this->request(['format' => 'json', 'store_id' => '3', 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('application/json', $response->headers()['Content-Type']);
        $this->assertStringContainsString('attachment; filename="kintai-ST3-2026-11-01_2026-11-30.json"', $response->headers()['Content-Disposition']);
        $data = json_decode($response->body(), true);
        $this->assertSame(1, $data['count']);
        $this->assertSame('Matin', $data['shifts'][0]['shift_type']);
        $this->assertSame(420, $data['shifts'][0]['net_minutes']);
    }

    public function testCsvIsDownloadedWithTheSameRows(): void
    {
        $response = $this->controller->download($this->request(['format' => 'csv', 'store_id' => '3', 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $this->assertStringContainsString('text/csv', $response->headers()['Content-Type']);
        $this->assertStringContainsString('2026-11-02;09:00;17:00;0;60;420;Matin;Shibuya;', $response->body());
    }

    public function testIcsContainsTheEventOfThePeriodOnly(): void
    {
        $response = $this->controller->download($this->request(['format' => 'ics', 'store_id' => '3', 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $this->assertStringContainsString('text/calendar', $response->headers()['Content-Type']);
        $this->assertSame(1, substr_count($response->body(), 'BEGIN:VEVENT'));
        $this->assertStringContainsString('UID:shift-1@kintai', $response->body());
    }

    public function testAStoreWhereIAmNotAMemberIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->download($this->request(['format' => 'json', 'store_id' => '4']));
    }

    public function testAnUnknownFormatGoesBackToTheProfile(): void
    {
        $response = $this->controller->download($this->request(['format' => 'xml', 'store_id' => '3']));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('/profile?tab=ical', $response->headers()['Location']);
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        unset($_SESSION['auth_user_id']);
        $response = $this->controller->download($this->request(['format' => 'json', 'store_id' => '3']));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('/login', $response->headers()['Location']);
    }
}
