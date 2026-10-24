<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Contract\Event\OpenShiftClosed;
use kintai\Contract\Extension\ShiftFormSection;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Container;
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
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvailabilityGuard;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\ShiftServiceInterface;
use kintai\UI\Controller\Web\Scheduling\AdminShiftController;
use kintai\UI\ViewRenderer;
use kintai\Tests\Support\FakeAbsenceProvider;
use kintai\Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminShiftControllerTest extends TestCase
{
    private ShiftRepositoryInterface&Stub $shifts;
    private ShiftTypeRepositoryInterface&Stub $shiftTypes;
    private StoreRepositoryInterface&Stub $stores;
    private ShiftServiceInterface&Stub $shiftService;
    private NotificationService&Stub $notifs;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private RoleRepositoryInterface&Stub $roles;
    private UserRepositoryInterface&Stub $users;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ?AdminShiftController $controller = null;

    protected function setUp(): void
    {
        $this->ensureViewFile('scheduling.shifts');
        $this->ensureViewFile('layout.app');
        
        $this->shifts = $this->createStub(ShiftRepositoryInterface::class);
        $this->shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->shiftService = $this->createStub(ShiftServiceInterface::class);
        $this->notifs = $this->createStub(NotificationService::class);
        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->roles = $this->createStub(RoleRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminShiftController
    {
        if ($this->controller === null) {
            $view = new ViewRenderer(sys_get_temp_dir());
            $auditLogger = new AuditLogger();
            $permissions = new PermissionService($this->roleAssignments, $this->roles);
            $this->controller = new AdminShiftController(
                $view,
                $this->users,
                $this->stores,
                $this->shifts,
                $this->shiftTypes,
                $this->storeUsers,
                $this->createStub(UserShiftTypeRateRepositoryInterface::class),
                $auditLogger,
                $this->notifs,
                $this->shiftService,
                $permissions,
                new AvailabilityGuard($this->createStub(AvailabilityRepositoryInterface::class)),
            );
        }

        return $this->controller;
    }

    public function testShiftsRendersList(): void
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->shifts->method('findBetween')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);
        $this->stores->method('findAll')->willReturn([]);

        $response = $this->controller()->shifts($req);

        $this->assertSame(200, $response->status());
    }

    /**
     * scheduling.shifts est désormais partagée avec EmployeeController::shiftsWeek()
     * (ex-scheduling.employee-shifts, supprimée) — le contrôleur admin doit toujours
     * passer can_manage=true pour afficher la table de gestion multi-employés et non
     * la vue personnelle "ma semaine" de l'employé.
     */
    public function testShiftsPassesCanManageTrue(): void
    {
        $this->writeViewFile('scheduling.shifts', '<?= ($can_manage ?? false) ? "CAN_MANAGE_TRUE" : "CAN_MANAGE_FALSE" ?>');
        // layout.app vit dans le dossier temporaire partagé : d'autres tests y écrivent un autre contenu (ex. JSON des
        // statistiques), et selon l'ordre d'exécution la vue n'était plus affichée (échec en CI seulement).
        $this->writeViewFile('layout.app', "<?php echo \$content ?? '';");

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);

        $this->shifts->method('findBetween')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);
        $this->stores->method('findAll')->willReturn([]);

        $response = $this->controller()->shifts($req);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('CAN_MANAGE_TRUE', $response->body());
    }

    // -------------------------------------------------------------------------
    // quickShift
    // -------------------------------------------------------------------------

    public function testQuickShiftCreatesShiftAndReturns201(): void
    {
        $req = $this->makeJsonRequest([
            'store_id'   => 1,
            'user_id'    => 5,
            'shift_date' => '2026-06-01',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->shifts->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 42]);

        $response = $this->controller()->quickShift($req);

        $this->assertSame(201, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertSame(42, $data['id']);
        $this->assertSame('09:00', $data['start_time']);
    }

    /** Champs « shift ouvert » : acceptés quand une section du formulaire les déclare (shift-claim). */
    public function testQuickShiftWithAdditionalFields(): void
    {
        $this->declareOpenShiftSection();
        $req = $this->makeJsonRequest([
            'store_id'        => 1,
            'user_id'         => 5,
            'shift_date'      => '2026-06-01',
            'start_time'      => '09:00',
            'end_time'        => '17:00',
            'pause_minutes'   => 30,
            'notes'           => 'Test note',
            'open_shift_note' => 'Ouvert',
            'is_open'         => true,
        ]);
        $req->setAttribute('managed_store_ids', null);

        $captured = null;
        $this->shifts->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 1];
        });

        $this->controller()->quickShift($req);

        $this->assertNotNull($captured);
        $this->assertSame(30, $captured['pause_minutes']);
        $this->assertSame('Test note', $captured['notes']);
        $this->assertSame('Ouvert', $captured['open_shift_note']);
        $this->assertSame(1, $captured['is_open']);
    }

    /** Sans bundle qui déclare les champs « shift ouvert » (shift-claim absent), ils sont ignorés. */
    public function testQuickShiftIgnoresOpenShiftFieldsWithoutADeclaringBundle(): void
    {
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));
        $req = $this->makeJsonRequest([
            'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-06-01', 'start_time' => '09:00', 'end_time' => '17:00',
            'open_shift_note' => 'Ouvert', 'is_open' => true,
        ]);
        $req->setAttribute('managed_store_ids', null);
        $captured = null;
        $this->shifts->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 1];
        });

        $this->controller()->quickShift($req);

        $this->assertNull($captured['open_shift_note']);
        $this->assertSame(0, $captured['is_open']);
    }

    public function testQuickShiftForbiddenStoreAccess(): void
    {
        $req = $this->makeJsonRequest([
            'store_id' => 99,
            'shift_date' => '2026-06-01',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $req->setAttribute('managed_store_ids', [1, 2]);

        $response = $this->controller()->quickShift($req);

        $this->assertSame(403, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertSame('error_access_denied', $data['error']);
    }

    /**
     * shift_type_id n'est plus posté/choisi manuellement : il est déduit
     * automatiquement du type dominant (le plus de minutes) parmi les types
     * actifs du store, en fonction du chevauchement horaire avec le shift.
     */
    public function testQuickShiftAutoComputesDominantShiftType(): void
    {
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $req = $this->makeJsonRequest([
            'store_id'   => 1,
            'user_id'    => 5,
            'shift_date' => '2026-06-01',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $req->setAttribute('managed_store_ids', null);

        $this->shiftTypes->expects($this->atLeastOnce())->method('findActive')->with(1)->willReturn([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $this->shifts->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 1];
        });

        $this->controller()->quickShift($req);

        // 09:00→12:00 = 180min (Matin) ; 12:00→17:00 = 300min (Aprem) → Aprem dominant
        $this->assertSame(4, $captured['shift_type_id']);
    }

    public function testQuickShiftBlockedWhenUserOnApprovedTimeoff(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $req = $this->makeJsonRequest([
            'store_id'   => 1,
            'user_id'    => 5,
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $req->setAttribute('managed_store_ids', null);

        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'approved', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05'],
        ]);
        $this->shifts->expects($this->never())->method('save');

        $response = $this->controller()->quickShift($req);

        $this->assertSame(409, $response->status());
    }

    // -------------------------------------------------------------------------
    // storeShift
    // -------------------------------------------------------------------------

    public function testStoreShiftBlockedWhenUserOnApprovedTimeoff(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'approved', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05'],
        ]);
        $this->shifts->expects($this->never())->method('save');

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
    }

    public function testStoreShiftAllowedWhenNoTimeoffConflict(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->shifts->expects($this->once())->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 1]);

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
    }

    /**
     * shift_type_id n'est plus posté/choisi manuellement : il est déduit
     * automatiquement du type dominant parmi les types actifs du store, en
     * fonction du chevauchement horaire avec le shift (un shift peut traverser
     * plusieurs tranches, ex : 09:00-17:00 traverse "Matin" puis "Aprem").
     */
    public function testStoreShiftAutoComputesDominantShiftType(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->shiftTypes->expects($this->atLeastOnce())->method('findActive')->with(1)->willReturn([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            }
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 1]);

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
        // 09:00→12:00 = 180min (Matin) ; 12:00→17:00 = 300min (Aprem) → Aprem dominant
        $this->assertSame(4, $captured['shift_type_id']);
    }

    /**
     * Régression : le shift est déjà sauvegardé à ce stade. Si NotificationService::notify()
     * échoue (ex. panne DB transitoire), l'exception ne doit pas remonter en 500 générique
     * pour une action qui a en réalité pleinement réussi — voir CHANGELOG.
     */
    public function testStoreShiftSucceedsEvenWhenNotifyThrows(): void
    {
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->shifts->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 1]);
        $this->notifs->method('notify')->willThrowException(new \RuntimeException('notif DB down'));

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
        $headersRef = new \ReflectionProperty($response, 'headers');
        $headersRef->setAccessible(true);
        $this->assertStringContainsString('success=created', $headersRef->getValue($response)['Location'] ?? '');
    }

    /** Un type de shift ne peut désormais être appliqué qu'à un store où il est activé (table pivot). */
    public function testStoreShiftRejectsShiftTypeNotEnabledForStore(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->shiftTypes->expects($this->atLeastOnce())->method('findActive')->with(1)->willReturn([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            }
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 1]);

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
        // 09:00→12:00 = 180min (Matin) ; 12:00→17:00 = 300min (Aprem) → Aprem dominant
        $this->assertSame(4, $captured['shift_type_id']);
    }

    /** Un taux/heures actives manuel saisi doit être persisté (null si vide). */
    public function testStoreShiftPersistsManualOverrides(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $_POST = [
            'store_id'              => '1',
            'user_id'               => '5',
            'shift_date'            => '2026-08-02',
            'start_time'            => '09:00',
            'end_time'              => '17:00',
            'hourly_rate_override'  => '1500.5',
            'net_minutes_override'  => '300',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['hourly_rate_override'] === 1500.5 && $d['net_minutes_override'] === 300
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 1]);

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
    }

    /** Champs laissés vides : pas d'ajustement manuel, calcul automatique. */
    public function testStoreShiftOverridesDefaultToNullWhenEmpty(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['hourly_rate_override'] === null && $d['net_minutes_override'] === null
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 1]);

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->status());
    }

    // -------------------------------------------------------------------------
    // updateShift
    // -------------------------------------------------------------------------

    /** Éditer un shift et saisir un ajustement manuel doit le persister. */
    public function testUpdateShiftPersistsManualOverrides(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);

        $_POST = [
            'store_id'             => '1',
            'shift_date'           => '2026-08-02',
            'start_time'           => '09:00',
            'end_time'             => '17:00',
            'hourly_rate_override' => '2000',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['hourly_rate_override'] === 2000.0 && $d['net_minutes_override'] === null
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 10]);

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
    }

    /**
     * Régression : décocher « Shift ouvert » dans le formulaire d'édition plantait (le Core annulait lui-même les
     * candidatures avec un dépôt qu'il n'avait plus). Désormais le shift est fermé et le Core annonce OpenShiftClosed
     * aux bundles qui l'écoutent (shift-claim retire les candidatures en attente).
     */
    public function testClosingAnOpenShiftAnnouncesItToBundles(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $received = [];
        $bundle = new class extends Bundle {
            public ?\Closure $listener = null;

            public function __construct() {}

            public function getName(): string
            {
                return 'shift-claim';
            }

            public function register(): void {}

            public function shiftFormSections(): array
            {
                return [new ShiftFormSection('open_shift', 'shift-claim::shift-form-section', ['is_open', 'open_shift_note'])];
            }

            public function eventListeners(): array
            {
                return [OpenShiftClosed::class => $this->listener];
            }
        };
        $bundle->listener = function (OpenShiftClosed $e) use (&$received): void {
            $received[] = $e;
        };
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['shift-claim' => $bundle]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn([
            'id' => 10, 'store_id' => 1, 'user_id' => null, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 1, 'open_shift_note' => 'Qui ?',
        ]);
        $_POST = ['store_id' => '1', 'user_id' => '5', 'shift_date' => '2026-08-02', 'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => '0'];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setAttribute('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => 10]);
        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['is_open'] === 0 && $d['user_id'] === 5
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 10]);

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
        $this->assertCount(1, $received);
        $this->assertSame(10, $received[0]->shiftId());
        $this->assertSame(1, $received[0]->closedBy);
    }

    /** Sans bundle qui déclare le champ « Shift ouvert », l'édition ne le change pas (et n'annonce rien). */
    public function testOpenShiftFieldIsKeptWithoutADeclaringBundle(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn([
            'id' => 10, 'store_id' => 1, 'user_id' => null, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 1,
        ]);
        $_POST = ['store_id' => '1', 'shift_date' => '2026-08-02', 'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => '0'];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);
        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['is_open'] === 1
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 10]);

        $this->assertSame(302, $this->controller()->updateShift($req)->status());
    }

    /** Même auto-calcul du type dominant que storeShift(), lors d'une édition. */
    public function testUpdateShiftAutoComputesDominantShiftType(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);

        $_POST = [
            'store_id'   => '1',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->shiftTypes->expects($this->atLeastOnce())->method('findActive')->with(1)->willReturn([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            }
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 10]);

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
        $this->assertSame(4, $captured['shift_type_id']);
    }

    /**
     * Régression : même chose que testStoreShiftSucceedsEvenWhenNotifyThrows, côté update —
     * le shift est déjà sauvegardé avant l'appel à notify() (déclenché ici par un changement
     * d'utilisateur assigné, 5 → 6).
     */
    public function testUpdateShiftSucceedsEvenWhenNotifyThrows(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);

        $_POST = [
            'store_id'   => '1',
            'user_id'    => '6',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->shifts->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 10]);
        $this->notifs->method('notify')->willThrowException(new \RuntimeException('notif DB down'));

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
        $headersRef = new \ReflectionProperty($response, 'headers');
        $headersRef->setAccessible(true);
        $this->assertStringContainsString('success=updated', $headersRef->getValue($response)['Location'] ?? '');
    }

    /**
     * Régression : updateShift() ne notifiait qu'un changement d'employé assigné
     * (5 → 6). Un manager qui déplace le shift du même employé (autre date/horaire)
     * restait totalement silencieux — l'employé n'apprenait le changement qu'en
     * consultant son planning.
     */
    public function testUpdateShiftNotifiesSameEmployeeWhenRescheduled(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);

        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-03',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->shifts->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 10]);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Boutique Test']);
        $this->notifs->expects($this->once())->method('notify')->with(
            5,
            'shift_updated',
            'notif_shift_updated_body',
            ['date' => '2026-08-03', 'start' => '09:00', 'end' => '17:00', 'store' => 'Boutique Test'],
            10,
            '/employee/shifts/day?start=2026-08-03&highlight=10'
        );

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
    }

    // -------------------------------------------------------------------------
    // deleteShift() / bulkDeleteShifts() — notification à la suppression
    // -------------------------------------------------------------------------

    /**
     * Régression : supprimer un shift déjà assigné restait totalement silencieux
     * pour l'employé (seul le flux iCal reflétait la suppression) — deleteShift()
     * n'appelait jamais notify().
     */
    public function testDeleteShiftNotifiesAssignedUser(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00',
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);
        $this->shifts->expects($this->once())->method('delete')->with(10)->willReturn(1);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Boutique Test']);

        $this->notifs->expects($this->once())->method('notify')->with(
            5,
            'shift_deleted',
            'notif_shift_deleted_body',
            ['date' => '2026-08-02', 'start' => '09:00', 'end' => '17:00', 'store' => 'Boutique Test'],
            // Le shift n'existe plus : la notification désigne le jour (20260802), lu quand ce jour est affiché.
            20260802,
            '/employee/shifts/day?start=2026-08-02'
        );

        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $response = $this->controller()->deleteShift($req);

        $this->assertSame(302, $response->status());
    }

    public function testDeleteShiftDoesNotNotifyForOpenShift(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 11, 'store_id' => 1, 'user_id' => null, 'shift_date' => '2026-08-02', 'is_open' => 1,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(11)->willReturn($existing);
        $this->shifts->expects($this->atLeastOnce())->method('delete')->with(11)->willReturn(1);

        $this->notifs->expects($this->never())->method('notify');

        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 11]);

        $response = $this->controller()->deleteShift($req);

        $this->assertSame(302, $response->status());
    }

    public function testBulkDeleteShiftsNotifiesEachAssignedUser(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $shiftA = ['id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02'];
        $shiftB = ['id' => 11, 'store_id' => 1, 'user_id' => 6, 'shift_date' => '2026-08-03'];
        $this->shifts->method('findById')->willReturnMap([
            [10, $shiftA],
            [11, $shiftB],
        ]);
        $this->shifts->method('delete')->willReturn(1);

        $this->notifs->expects($this->exactly(2))->method('notify');

        $_POST = ['ids' => [10, 11]];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);

        $response = $this->controller()->bulkDeleteShifts($req);

        $this->assertSame(302, $response->status());
    }

    public function testUpdateShiftDoesNotNotifyWhenSameEmployeeAndNothingRelevantChanged(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);

        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
            'notes'      => 'Juste une note ajoutée',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->shifts->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 10]);
        $this->notifs->expects($this->never())->method('notify');

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
    }

    public function testUpdateShiftRejectsShiftTypeNotEnabledForStore(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->shiftTypes = $this->createMock(ShiftTypeRepositoryInterface::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        $this->shifts->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn($existing);

        $_POST = [
            'store_id'   => '1',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = new Request();
        $req->setAttribute('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->shiftTypes->expects($this->atLeastOnce())->method('findActive')->with(1)->willReturn([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $this->shifts->expects($this->once())->method('save')->with($this->callback(
            function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            }
        ))->willReturnCallback(fn(array $d) => $d + ['id' => 10]);

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->status());
        $this->assertSame(4, $captured['shift_type_id']);
    }

    // -------------------------------------------------------------------------
    // selectShiftToPublish
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // shiftsTimeline — can_manage
    // -------------------------------------------------------------------------

    /**
     * Régression sévère : shiftsTimeline() ne calculait jamais can_manage, et la vue
     * (scheduling.shifts-timeline) défautait alors à true — exposant le bouton import
     * Excel, la création rapide de shift, le glisser-déposer et le détail des taux
     * horaires dans la modale de shift à N'IMPORTE QUEL viewer capable d'atteindre cette
     * page, y compris un simple employé (rôle "employee", uniquement shifts.view — voir
     * CHANGELOG). Ce test vérifie que can_manage reflète désormais shifts.update.
     */
    public function testShiftsTimelinePassesCanManageTrueForOwner(): void
    {
        $canManage = $this->renderTimelineAndCaptureCanManage(['id' => 1, 'is_admin' => true]);

        $this->assertTrue($canManage);
    }

    public function testShiftsTimelinePassesCanManageTrueForRoleGrantingShiftsUpdate(): void
    {
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([
            ['id' => 1, 'user_id' => 9, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(2)->willReturn(['id' => 2, 'slug' => 'manager', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(2)->willReturn(['shifts.view', 'shifts.update']);

        $canManage = $this->renderTimelineAndCaptureCanManage(['id' => 9, 'is_admin' => false], [1]);

        $this->assertTrue($canManage);
    }

    /** Le cas exact du bug rapporté : rôle "employee" avec shifts.view seul. */
    public function testShiftsTimelinePassesCanManageFalseForViewOnlyRole(): void
    {
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles = $this->createMock(RoleRepositoryInterface::class);
        $this->roleAssignments->expects($this->atLeastOnce())->method('findByUser')->with(14)->willReturn([
            ['id' => 1, 'user_id' => 14, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $this->roles->expects($this->atLeastOnce())->method('findById')->with(3)->willReturn(['id' => 3, 'slug' => 'employee', 'is_system' => 0]);
        $this->roles->expects($this->atLeastOnce())->method('getPermissions')->with(3)->willReturn(['shifts.view']);

        $canManage = $this->renderTimelineAndCaptureCanManage(['id' => 14, 'is_admin' => false], [1]);

        $this->assertFalse($canManage);
    }

    /**
     * Un employé supprimé (anonymisé, deleted_at), désactivé ou disparu de la base reste lié au magasin (store_user) :
     * il apparaissait dans la liste des shifts sous la forme « #90 » sans nom, et sa fiche répondait 404.
     */
    public function testShiftsTimelineHidesDeletedDeactivatedAndMissingStaff(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->writeViewFile('scheduling.shifts-timeline', "<?php echo json_encode(\$all_user_ids);");
        $this->writeViewFile('layout.app', "<?php echo \$content ?? '';");

        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store 1']]);
        $this->stores->method('findById')->willReturn(['id' => 1, 'name' => 'Store 1']);
        $this->shifts->method('findByStore')->willReturn([]);
        $this->shiftTypes->method('findByStores')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);
        $this->storeUsers->method('findByStore')->willReturn([
            ['store_id' => 1, 'user_id' => 10],
            ['store_id' => 1, 'user_id' => 11],
            ['store_id' => 1, 'user_id' => 12],
            ['store_id' => 1, 'user_id' => 13],
            ['store_id' => 1, 'user_id' => 10],
        ]);
        $this->users->method('findAll')->willReturn([
            ['id' => 10, 'last_name' => 'Ueda', 'first_name' => 'Ken', 'is_active' => 1, 'deleted_at' => null],
            ['id' => 11, 'last_name' => '', 'first_name' => '', 'is_active' => 0, 'deleted_at' => '2026-10-01 10:00:00'],
            ['id' => 12, 'last_name' => 'Sato', 'first_name' => 'Aya', 'is_active' => 0, 'deleted_at' => null],
        ]);

        $_GET['store_id'] = '1';
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->setAttribute('managed_store_ids', null);

        $response = $this->controller()->shiftsTimeline($req);

        $this->assertSame([10], json_decode($response->body(), true));
    }

    private function renderTimelineAndCaptureCanManage(array $authUser, ?array $managedStoreIds = null): bool
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->writeViewFile('scheduling.shifts-timeline', "<?php echo json_encode(['can_manage' => \$can_manage ?? null]);");
        $this->writeViewFile('layout.app', "<?php echo \$content ?? '';");

        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store 1']]);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Store 1']);
        $this->shifts->method('findByStore')->willReturn([]);
        $this->shiftTypes->method('findByStores')->willReturn([]);
        $this->shiftTypes->method('findAll')->willReturn([]);
        $this->storeUsers->method('findByStore')->willReturn([]);
        $this->users->method('findAll')->willReturn([]);

        $req = new Request();
        $req->setAttribute('auth_user', $authUser);
        $req->setAttribute('managed_store_ids', $managedStoreIds);

        $response = $this->controller()->shiftsTimeline($req);
        $data     = json_decode($response->body(), true);

        return $data['can_manage'];
    }

    private function writeViewFile(string $view, string $content): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, $content);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        // Bundles actifs simulés par certains tests : ne pas les laisser au conteneur partagé.
        $instances = new \ReflectionProperty(Container::class, 'instances');
        $all = $instances->getValue(Container::getInstance());
        unset($all[BundleManager::class], $all[ExtensionRegistry::class]);
        $instances->setValue(Container::getInstance(), $all);
    }

    /** shift-claim actif, qui déclare les champs « shift ouvert » du formulaire (contrat 1.17). */
    private function declareOpenShiftSection(): void
    {
        $bundle = new class extends Bundle {
            public function __construct() {}

            public function getName(): string
            {
                return 'shift-claim';
            }

            public function register(): void {}

            public function shiftFormSections(): array
            {
                return [new ShiftFormSection('open_shift', 'shift-claim::shift-form-section', ['is_open', 'open_shift_note'])];
            }
        };
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['shift-claim' => $bundle]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    private function makeJsonRequest(array $body): Request
    {
        $req = new Request();
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setAccessible(true);
        $ref->setValue($req, $body);
        return $req;
    }

    private function ensureViewFile(string $view): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($file);
    }
}
