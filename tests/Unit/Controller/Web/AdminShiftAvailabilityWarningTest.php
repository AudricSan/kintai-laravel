<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Models\Availability;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvailabilityGuard;
use App\Services\NotificationService;
use App\Services\ShiftServiceInterface;
use App\Http\Controllers\Scheduling\AdminShiftController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
    private AdminShiftController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        foreach (['scheduling.shifts-form', 'layout.app'] as $view) {
            $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            touch($file);
        }
        kintai_test_availabilities([5, 7, 9], [
            ['day_of_week' => 7, 'is_available' => 0, 'start_time' => '00:00', 'end_time' => '23:59'],
            ['day_of_week' => 1, 'is_available' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]);
        kintai_test_shifts([[
            'id' => 9, 'store_id' => 1, 'user_id' => 5, 'shift_date' => self::MONDAY,
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ]]);
        $this->controller = new AdminShiftController(
            kintai_test_views(sys_get_temp_dir()),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            new AuditLogger(),
            $this->createStub(NotificationService::class),
            $this->createStub(ShiftServiceInterface::class),
            new PermissionService(),
            new AvailabilityGuard(),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function postRequest(array $post, ?int $routeId = null): Request
    {
        $_POST = $post;
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        return $routeId === null ? $req : $this->withId($req, $routeId);
    }

    private function jsonRequest(array $body, ?int $routeId = null): Request
    {
        $req = Request::capture();
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

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
        $before = Shift::query()->count();
        $response = $this->controller->storeShift($this->postRequest($this->shiftPost(self::SUNDAY)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($before, Shift::query()->count(), 'rien n\'est enregistré');
    }

    public function testForcingTheScheduleSavesTheShiftAnyway(): void
    {
        $before = Shift::query()->count();
        $response = $this->controller->storeShift($this->postRequest($this->shiftPost(self::SUNDAY, ['force_availability' => '1'])));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($before + 1, Shift::query()->count());
    }

    public function testAnAvailableDayIsSavedWithoutWarning(): void
    {
        $before = Shift::query()->count();
        $response = $this->controller->storeShift($this->postRequest($this->shiftPost(self::MONDAY)));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($before + 1, Shift::query()->count());
    }

    // ── Formulaire de modification ──────────────────────────────────────────

    public function testMovingAnExistingShiftToAnUnavailableDayWarns(): void
    {
        $response = $this->controller->updateShift($this->postRequest($this->shiftPost(self::SUNDAY), 9));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditingOtherFieldsOfAShiftDoesNotAskAgain(): void
    {
        // Même personne, même date que le shift enregistré : aucun avertissement.
        $response = $this->controller->updateShift($this->postRequest($this->shiftPost(self::MONDAY, ['end_time' => '18:00']), 9));

        $this->assertSame(302, $response->getStatusCode());
    }

    // ── Création rapide et déplacement (JSON) ───────────────────────────────

    public function testQuickShiftAnswers409WithACodeThenAcceptsTheForcedRequest(): void
    {
        $body = ['store_id' => 1, 'user_id' => 5, 'shift_date' => self::SUNDAY, 'start_time' => '09:00', 'end_time' => '17:00'];

        $refused = $this->controller->quickShift($this->jsonRequest($body));
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertSame('availability_conflict', json_decode((string) $refused->getContent(), true)['code']);

        $forced = $this->controller->quickShift($this->jsonRequest($body + ['force_availability' => true]));
        $this->assertSame(201, $forced->getStatusCode());
    }

    public function testMovingByDragAnswers409WithACodeThenAcceptsTheForcedRequest(): void
    {
        $body = ['shift_date' => self::SUNDAY, 'start_time' => '09:00', 'end_time' => '17:00'];

        $refused = $this->controller->moveShift($this->jsonRequest($body, 9));
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertSame('availability_conflict', json_decode((string) $refused->getContent(), true)['code']);

        $forced = $this->controller->moveShift($this->jsonRequest($body + ['force_availability' => true], 9));
        $this->assertSame(200, $forced->getStatusCode());
    }

    public function testChangingOnlyTheHoursByDragDoesNotWarn(): void
    {
        $response = $this->controller->moveShift($this->jsonRequest(['shift_date' => self::MONDAY, 'start_time' => '10:00', 'end_time' => '18:00'], 9));

        $this->assertSame(200, $response->getStatusCode());
    }
}
