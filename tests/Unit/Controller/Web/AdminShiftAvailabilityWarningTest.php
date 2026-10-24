<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvailabilityGuard;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\ShiftServiceInterface;
use kintai\UI\Controller\Web\Scheduling\AdminShiftController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Planifier quelqu'un un jour où il s'est déclaré non disponible : avertissement (formulaire réaffiché, ou 409 en
 * JSON pour le glisser-déposer), jamais un blocage — « forcer l'horaire » enregistre malgré tout.
 */
final class AdminShiftAvailabilityWarningTest extends TestCase
{
    /** 2026-08-02 est un dimanche : day_of_week 7. */
    private const SUNDAY = '2026-08-02';
    private const MONDAY = '2026-08-03';

    private ShiftRepositoryInterface&MockObject $shifts;
    private AdminShiftController $controller;

    protected function setUp(): void
    {
        foreach (['scheduling.shifts-form', 'layout.app'] as $view) {
            $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            touch($file);
        }

        $availabilities = $this->createStub(AvailabilityRepositoryInterface::class);
        $availabilities->method('findByUserAndStore')->willReturn([
            ['day_of_week' => 7, 'is_available' => 0, 'start_time' => '00:00', 'end_time' => '23:59'],
            ['day_of_week' => 1, 'is_available' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]);

        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shifts->method('findById')->willReturn([
            'id' => 9, 'store_id' => 1, 'user_id' => 5, 'shift_date' => self::MONDAY,
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ]);
        $this->shifts->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 1]);

        $this->controller = new AdminShiftController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->shifts,
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            $this->createStub(NotificationService::class),
            $this->createStub(ShiftServiceInterface::class),
            new PermissionService($this->createStub(RoleAssignmentRepositoryInterface::class), $this->createStub(RoleRepositoryInterface::class)),
            new AvailabilityGuard($availabilities),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function postRequest(array $post, ?int $routeId = null): Request
    {
        $_POST = $post;
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        return $routeId === null ? $req : $this->withId($req, $routeId);
    }

    private function jsonRequest(array $body, ?int $routeId = null): Request
    {
        $req = new Request();
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setValue($req, $body);
        $req->setAttribute('managed_store_ids', null);
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        return $routeId === null ? $req : $this->withId($req, $routeId);
    }

    private function withId(Request $request, int $id): Request
    {
        $request->setRouteParams(['id' => (string) $id]);

        return $request;
    }

    private function shiftPost(string $date, array $extra = []): array
    {
        return ['store_id' => '1', 'user_id' => '5', 'shift_date' => $date, 'start_time' => '09:00', 'end_time' => '17:00'] + $extra;
    }

    // ── Formulaire de création ──────────────────────────────────────────────

    public function testCreatingAShiftOnAnUnavailableDayRedisplaysTheFormWithoutSaving(): void
    {
        $this->shifts->expects($this->never())->method('save');

        $response = $this->controller->storeShift($this->postRequest($this->shiftPost(self::SUNDAY)));

        $this->assertSame(200, $response->status());
    }

    public function testForcingTheScheduleSavesTheShiftAnyway(): void
    {
        $this->shifts->expects($this->once())->method('save');

        $response = $this->controller->storeShift($this->postRequest($this->shiftPost(self::SUNDAY, ['force_availability' => '1'])));

        $this->assertSame(302, $response->status());
    }

    public function testAnAvailableDayIsSavedWithoutWarning(): void
    {
        $this->shifts->expects($this->once())->method('save');

        $response = $this->controller->storeShift($this->postRequest($this->shiftPost(self::MONDAY)));

        $this->assertSame(302, $response->status());
    }

    // ── Formulaire de modification ──────────────────────────────────────────

    public function testMovingAnExistingShiftToAnUnavailableDayWarns(): void
    {
        $this->shifts->expects($this->never())->method('save');

        $response = $this->controller->updateShift($this->postRequest($this->shiftPost(self::SUNDAY), 9));

        $this->assertSame(200, $response->status());
    }

    public function testEditingOtherFieldsOfAShiftDoesNotAskAgain(): void
    {
        $this->shifts->expects($this->once())->method('save');

        // Même personne, même date que le shift enregistré : aucun avertissement.
        $response = $this->controller->updateShift($this->postRequest($this->shiftPost(self::MONDAY, ['end_time' => '18:00']), 9));

        $this->assertSame(302, $response->status());
    }

    // ── Création rapide et déplacement (JSON) ───────────────────────────────

    public function testQuickShiftAnswers409WithACodeThenAcceptsTheForcedRequest(): void
    {
        $body = ['store_id' => 1, 'user_id' => 5, 'shift_date' => self::SUNDAY, 'start_time' => '09:00', 'end_time' => '17:00'];

        $refused = $this->controller->quickShift($this->jsonRequest($body));
        $this->assertSame(409, $refused->status());
        $this->assertSame('availability_conflict', json_decode((string) $refused->body(), true)['code']);

        $forced = $this->controller->quickShift($this->jsonRequest($body + ['force_availability' => true]));
        $this->assertSame(201, $forced->status());
    }

    public function testMovingByDragAnswers409WithACodeThenAcceptsTheForcedRequest(): void
    {
        $body = ['shift_date' => self::SUNDAY, 'start_time' => '09:00', 'end_time' => '17:00'];

        $refused = $this->controller->moveShift($this->jsonRequest($body, 9));
        $this->assertSame(409, $refused->status());
        $this->assertSame('availability_conflict', json_decode((string) $refused->body(), true)['code']);

        $forced = $this->controller->moveShift($this->jsonRequest($body + ['force_availability' => true], 9));
        $this->assertSame(200, $forced->status());
    }

    public function testChangingOnlyTheHoursByDragDoesNotWarn(): void
    {
        $response = $this->controller->moveShift($this->jsonRequest(['shift_date' => self::MONDAY, 'start_time' => '10:00', 'end_time' => '18:00'], 9));

        $this->assertSame(200, $response->status());
    }
}
