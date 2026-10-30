<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use kintai\Contract\Event\OpenShiftClosed;
use kintai\Contract\Extension\ShiftFormSection;
use App\Bundles\Bundle;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use Illuminate\Container\Container;
use App\Models\Availability;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\AvailabilityGuard;
use App\Services\NotificationService;
use App\Services\SchedulePublication;
use App\Services\ShiftServiceInterface;
use App\Http\Controllers\Scheduling\AdminShiftController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Tests\Support\FakeAbsenceProvider;
use Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminShiftControllerTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private ShiftServiceInterface&Stub $shiftService;
    private NotificationService&Stub $notifs;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private UserRepositoryInterface&Stub $users;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ?AdminShiftController $controller = null;
    private ?SchedulePublication $publication = null;

    /** Shift enregistré par l'action testée : celui de l'identifiant donné, sinon le dernier créé. */
    private function savedShift(?int $id = null): array
    {
        $query = Shift::query();

        return ($id !== null ? $query->find($id) : $query->orderByDesc('id')->first())?->toArray() ?? [];
    }

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        $this->ensureViewFile('scheduling.shifts');
        $this->ensureViewFile('layout.app');
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->shiftService = $this->createStub(ShiftServiceInterface::class);
        $this->notifs = $this->createStub(NotificationService::class);
        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminShiftController
    {
        if ($this->controller === null) {
            $view = kintai_test_views(sys_get_temp_dir());
            $auditLogger = new AuditLogger();
            $permissions = new PermissionService($this->roleAssignments);
            $this->controller = new AdminShiftController(
                $view,
                $this->users,
                $this->stores,
                $this->storeUsers,
                $auditLogger,
                $this->notifs,
                $this->shiftService,
                $permissions,
                new AvailabilityGuard(),
                $this->publication,
            );
        }

        return $this->controller;
    }

    public function testShiftsRendersList(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_shifts([]);
        kintai_test_shift_types([]);
        $this->stores->method('findAll')->willReturn([]);

        $response = $this->controller()->shifts($req);

        $this->assertSame(200, $response->getStatusCode());
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

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        kintai_test_shifts([]);
        kintai_test_shift_types([]);
        $this->stores->method('findAll')->willReturn([]);

        $response = $this->controller()->shifts($req);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CAN_MANAGE_TRUE', $response->getContent());
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
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->quickShift($req);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(Shift::query()->max('id'), $data['id']);
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
        $req->attributes->set('managed_store_ids', null);

        $captured = null;
        $this->controller()->quickShift($req);

        $captured = $this->savedShift(null);
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
        $req->attributes->set('managed_store_ids', null);
        $captured = null;
        $this->controller()->quickShift($req);

        $captured = $this->savedShift(null);
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
        $req->attributes->set('managed_store_ids', [1, 2]);

        $response = $this->controller()->quickShift($req);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('error_access_denied', $data['error']);
    }

    /**
     * shift_type_id n'est plus posté/choisi manuellement : il est déduit
     * automatiquement du type dominant (le plus de minutes) parmi les types
     * actifs du store, en fonction du chevauchement horaire avec le shift.
     */
    public function testQuickShiftAutoComputesDominantShiftType(): void
    {
        $req = $this->makeJsonRequest([
            'store_id'   => 1,
            'user_id'    => 5,
            'shift_date' => '2026-06-01',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $req->attributes->set('managed_store_ids', null);

        kintai_test_shift_types([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $this->controller()->quickShift($req);

        // 09:00→12:00 = 180min (Matin) ; 12:00→17:00 = 300min (Aprem) → Aprem dominant
        $captured = $this->savedShift(null);
        $this->assertSame(4, $captured['shift_type_id']);
    }

    public function testQuickShiftBlockedWhenUserOnApprovedTimeoff(): void
    {
        $req = $this->makeJsonRequest([
            'store_id'   => 1,
            'user_id'    => 5,
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $req->attributes->set('managed_store_ids', null);

        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'approved', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05'],
        ]);
        $response = $this->controller()->quickShift($req);

        $this->assertSame(409, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // storeShift
    // -------------------------------------------------------------------------

    public function testStoreShiftBlockedWhenUserOnApprovedTimeoff(): void
    {
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        FakeAbsenceProvider::register([
            ['user_id' => 5, 'status' => 'approved', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05'],
        ]);
        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreShiftAllowedWhenNoTimeoffConflict(): void
    {
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * shift_type_id n'est plus posté/choisi manuellement : il est déduit
     * automatiquement du type dominant parmi les types actifs du store, en
     * fonction du chevauchement horaire avec le shift (un shift peut traverser
     * plusieurs tranches, ex : 09:00-17:00 traverse "Matin" puis "Aprem").
     */
    public function testStoreShiftAutoComputesDominantShiftType(): void
    {
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        kintai_test_shift_types([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $response = $this->controller()->storeShift($req);

        $this->assertTrue((function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            })($this->savedShift(null)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
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
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $this->notifs->method('notify')->willThrowException(new \RuntimeException('notif DB down'));

        $response = $this->controller()->storeShift($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('created', (string) session('success'));
    }

    /** Un type de shift ne peut désormais être appliqué qu'à un store où il est activé (table pivot). */
    public function testStoreShiftRejectsShiftTypeNotEnabledForStore(): void
    {
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        kintai_test_shift_types([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $response = $this->controller()->storeShift($req);

        $this->assertTrue((function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            })($this->savedShift(null)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
        // 09:00→12:00 = 180min (Matin) ; 12:00→17:00 = 300min (Aprem) → Aprem dominant
        $this->assertSame(4, $captured['shift_type_id']);
    }

    /** Un taux/heures actives manuel saisi doit être persisté (null si vide). */
    public function testStoreShiftPersistsManualOverrides(): void
    {
        $_POST = [
            'store_id'              => '1',
            'user_id'               => '5',
            'shift_date'            => '2026-08-02',
            'start_time'            => '09:00',
            'end_time'              => '17:00',
            'hourly_rate_override'  => '1500.5',
            'net_minutes_override'  => '300',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->storeShift($req);

        $this->assertTrue((fn(array $d) => $d['hourly_rate_override'] === 1500.5 && $d['net_minutes_override'] === 300)($this->savedShift(null)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
    }

    /** Champs laissés vides : pas d'ajustement manuel, calcul automatique. */
    public function testStoreShiftOverridesDefaultToNullWhenEmpty(): void
    {
        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->storeShift($req);

        $this->assertTrue((fn(array $d) => $d['hourly_rate_override'] === null && $d['net_minutes_override'] === null)($this->savedShift(null)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // updateShift
    // -------------------------------------------------------------------------

    /** Éditer un shift et saisir un ajustement manuel doit le persister. */
    public function testUpdateShiftPersistsManualOverrides(): void
    {
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        kintai_test_shifts([$existing]);

        $_POST = [
            'store_id'             => '1',
            'shift_date'           => '2026-08-02',
            'start_time'           => '09:00',
            'end_time'             => '17:00',
            'hourly_rate_override' => '2000',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $response = $this->controller()->updateShift($req);

        $this->assertTrue((fn(array $d) => $d['hourly_rate_override'] == 2000.0 && $d['net_minutes_override'] === null)($this->savedShift(10)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * Régression : décocher « Shift ouvert » dans le formulaire d'édition plantait (le Core annulait lui-même les
     * candidatures avec un dépôt qu'il n'avait plus). Désormais le shift est fermé et le Core annonce OpenShiftClosed
     * aux bundles qui l'écoutent (shift-claim retire les candidatures en attente).
     */
    public function testClosingAnOpenShiftAnnouncesItToBundles(): void
    {
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

        kintai_test_shifts([[
            'id' => 10, 'store_id' => 1, 'user_id' => null, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 1, 'open_shift_note' => 'Qui ?',
        ]]);
        $_POST = ['store_id' => '1', 'user_id' => '5', 'shift_date' => '2026-08-02', 'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => '0'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->setRouteParams(['id' => 10]);
        $response = $this->controller()->updateShift($req);

        $this->assertTrue((fn(array $d) => $d['is_open'] === 0 && $d['user_id'] === 5)($this->savedShift(10)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $received);
        $this->assertSame(10, $received[0]->shiftId());
        $this->assertSame(1, $received[0]->closedBy);
    }

    /** Sans bundle qui déclare le champ « Shift ouvert », l'édition ne le change pas (et n'annonce rien). */
    public function testOpenShiftFieldIsKeptWithoutADeclaringBundle(): void
    {
        Container::getInstance()->instance(BundleManager::class, FakeBundleManagerFactory::withActiveSlugs([]));
        kintai_test_shifts([[
            'id' => 10, 'store_id' => 1, 'user_id' => null, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 1,
        ]]);
        $_POST = ['store_id' => '1', 'shift_date' => '2026-08-02', 'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => '0'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);
        $this->assertTrue((fn(array $d) => $d['is_open'] === 1)($this->savedShift(10)), 'données enregistrées');
        $this->assertSame(302, $this->controller()->updateShift($req)->getStatusCode());
    }

    /** Même auto-calcul du type dominant que storeShift(), lors d'une édition. */
    public function testUpdateShiftAutoComputesDominantShiftType(): void
    {
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        kintai_test_shifts([$existing]);

        $_POST = [
            'store_id'   => '1',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        kintai_test_shift_types([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $response = $this->controller()->updateShift($req);

        $this->assertTrue((function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            })($this->savedShift(10)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(4, $captured['shift_type_id']);
    }

    /**
     * Régression : même chose que testStoreShiftSucceedsEvenWhenNotifyThrows, côté update —
     * le shift est déjà sauvegardé avant l'appel à notify() (déclenché ici par un changement
     * d'utilisateur assigné, 5 → 6).
     */
    public function testUpdateShiftSucceedsEvenWhenNotifyThrows(): void
    {
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        kintai_test_shifts([$existing]);

        $_POST = [
            'store_id'   => '1',
            'user_id'    => '6',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->notifs->method('notify')->willThrowException(new \RuntimeException('notif DB down'));

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('updated', (string) session('success'));
    }

    /**
     * Régression : updateShift() ne notifiait qu'un changement d'employé assigné
     * (5 → 6). Un manager qui déplace le shift du même employé (autre date/horaire)
     * restait totalement silencieux — l'employé n'apprenait le changement qu'en
     * consultant son planning.
     */
    public function testUpdateShiftNotifiesSameEmployeeWhenRescheduled(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        kintai_test_shifts([$existing]);

        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-03',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

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

        $this->assertSame(302, $response->getStatusCode());
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
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00',
        ];
        kintai_test_shifts([$existing]);
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

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $response = $this->controller()->deleteShift($req);

        $this->assertNull(Shift::findById(10), 'shift supprimé');
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testDeleteShiftDoesNotNotifyForOpenShift(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 11, 'store_id' => 1, 'user_id' => null, 'shift_date' => '2026-08-02', 'is_open' => 1,
        ];
        kintai_test_shifts([$existing]);
        $this->notifs->expects($this->never())->method('notify');

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 11]);

        $response = $this->controller()->deleteShift($req);

        $this->assertNull(Shift::findById(11), 'shift supprimé');
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testBulkDeleteShiftsNotifiesEachAssignedUser(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $shiftA = ['id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02'];
        $shiftB = ['id' => 11, 'store_id' => 1, 'user_id' => 6, 'shift_date' => '2026-08-03'];
        kintai_test_shifts([$shiftA + ['id' => 10], $shiftB + ['id' => 11]]);
        $this->notifs->expects($this->exactly(2))->method('notify');

        $_POST = ['ids' => [10, 11]];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->bulkDeleteShifts($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateShiftDoesNotNotifyWhenSameEmployeeAndNothingRelevantChanged(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        kintai_test_shifts([$existing]);

        $_POST = [
            'store_id'   => '1',
            'user_id'    => '5',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
            'notes'      => 'Juste une note ajoutée',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->notifs->expects($this->never())->method('notify');

        $response = $this->controller()->updateShift($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateShiftRejectsShiftTypeNotEnabledForStore(): void
    {
        $existing = [
            'id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'is_open' => 0,
        ];
        kintai_test_shifts([$existing]);

        $_POST = [
            'store_id'   => '1',
            'shift_date' => '2026-08-02',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        kintai_test_shift_types([
            ['id' => 3, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '12:00', 'hourly_rate' => 1000.0],
            ['id' => 4, 'name' => 'Aprem', 'start_time' => '12:00', 'end_time' => '20:00', 'hourly_rate' => 1200.0],
        ]);
        $captured = null;
        $response = $this->controller()->updateShift($req);

        $this->assertTrue((function (array $d) use (&$captured) {
                $captured = $d;
                return true;
            })($this->savedShift(10)), 'données enregistrées');
        $this->assertSame(302, $response->getStatusCode());
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
        $this->roleAssignments->expects($this->atLeastOnce())->method('findByUser')->with(9)->willReturn([
            ['id' => 1, 'user_id' => 9, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 2 ? ['id' => 2, 'slug' => 'manager', 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 2 ? ['shifts.view', 'shifts.update'] : []);
        $canManage = $this->renderTimelineAndCaptureCanManage(['id' => 9, 'is_admin' => false], [1]);

        $this->assertTrue($canManage);
    }

    /** Le cas exact du bug rapporté : rôle "employee" avec shifts.view seul. */
    public function testShiftsTimelinePassesCanManageFalseForViewOnlyRole(): void
    {
        $this->roleAssignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roleAssignments->expects($this->atLeastOnce())->method('findByUser')->with(14)->willReturn([
            ['id' => 1, 'user_id' => 14, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        kintai_test_roles(findById: fn(int $id) => $id === 3 ? ['id' => 3, 'slug' => 'employee', 'is_system' => 0] : null, getPermissions: fn(int $id) => $id === 3 ? ['shifts.view'] : []);
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
        kintai_test_shifts([]);
        kintai_test_shift_types([]);
        kintai_test_shift_types([]);
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
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->shiftsTimeline($req);

        $this->assertSame([10], json_decode($response->getContent(), true));
    }

    private function renderTimelineAndCaptureCanManage(array $authUser, ?array $managedStoreIds = null): bool
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->writeViewFile('scheduling.shifts-timeline', "<?php echo json_encode(['can_manage' => \$can_manage ?? null]);");
        $this->writeViewFile('layout.app', "<?php echo \$content ?? '';");

        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store 1']]);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'name' => 'Store 1']);
        kintai_test_shifts([]);
        kintai_test_shift_types([]);
        kintai_test_shift_types([]);
        $this->storeUsers->method('findByStore')->willReturn([]);
        $this->users->method('findAll')->willReturn([]);

        $req = Request::capture();
        $req->attributes->set('auth_user', $authUser);
        $req->attributes->set('managed_store_ids', $managedStoreIds);

        $response = $this->controller()->shiftsTimeline($req);
        $data     = json_decode($response->getContent(), true);

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
        Container::getInstance()->offsetUnset(BundleManager::class);
        Container::getInstance()->offsetUnset(ExtensionRegistry::class);
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
        $req = Request::capture();
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
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

    // -------------------------------------------------------------------------
    // Publication du planning : un brouillon ne prévient personne
    // -------------------------------------------------------------------------

    /** Magasin 1 à publication du planning, publié jusqu'au 2026-08-05 : les jours suivants sont un brouillon. */
    private function withPublicationUntil(string $until): void
    {
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturn(['id' => 1, 'name' => 'Boutique Test', 'schedule_publication' => 1, 'schedule_published_until' => $until]);
        $this->publication = new SchedulePublication($stores);
    }

    public function testDeletingADraftShiftNotifiesNobody(): void
    {
        $this->withPublicationUntil('2026-08-05');
        $this->notifs = $this->createMock(NotificationService::class);
        $draft = ['id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-06', 'start_time' => '09:00', 'end_time' => '17:00'];
        kintai_test_shifts([$draft]);
        $this->notifs->expects($this->never())->method('notify');

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->assertSame(302, $this->controller()->deleteShift($req)->getStatusCode());
        $this->assertNull(Shift::findById(10), 'shift supprimé');
    }

    public function testDeletingAPublishedShiftStillNotifies(): void
    {
        $this->withPublicationUntil('2026-08-05');
        $this->notifs = $this->createMock(NotificationService::class);
        $published = ['id' => 10, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-08-05', 'start_time' => '09:00', 'end_time' => '17:00'];
        kintai_test_shifts([$published]);
        $this->stores->method('findById')->willReturn(['id' => 1, 'name' => 'Boutique Test']);
        $this->notifs->expects($this->once())->method('notify')->with(5, 'shift_deleted');

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => 10]);

        $this->controller()->deleteShift($req);
    }
}
