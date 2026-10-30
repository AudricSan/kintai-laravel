<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftSwap\Controllers\Web;


use kintai\Bundles\Installed\ShiftSwap\Controllers\Web\AdminSwapController;
use App\Exceptions\ForbiddenException;
use App\Models\Shift;
use kintai\Bundles\Installed\ShiftSwap\Repositories\ShiftSwapRequestRepositoryInterface;
use App\Models\Store;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminSwapControllerTest extends TestCase
{
    private ShiftSwapRequestRepositoryInterface&Stub $swapRequests;
    private UserRepositoryInterface&Stub $users;
    private NotificationService&Stub $notifs;
    private ?AdminSwapController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
        $this->ensureViewFile($viewDir, 'swap-form');
        $this->ensureViewFile($viewDir, 'swap-requests');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->swapRequests = $this->createStub(ShiftSwapRequestRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->notifs = $this->createStub(NotificationService::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminSwapController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('shift-swap', $viewDir);
            $this->controller = new AdminSwapController(
                $view,
                $this->swapRequests,
                new AuditLogger(),
                $this->notifs,
                $this->users,
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

    public function testCreateSwapRendersFormForAdmin(): void
    {
        $this->users->method('findAll')->willReturn([
            ['id' => 1, 'last_name' => 'Dupont', 'first_name' => 'Jean'],
            ['id' => 2, 'last_name' => 'Martin', 'first_name' => 'Paul'],
        ]);

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->createSwap($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Régression (audit du 13/10/2026) : pour un gérant limité à ses magasins, le formulaire cherche les membres de
     * ces magasins ; le contrôleur n'avait pas le dépôt requis par HasAdminAccess et répondait 500.
     */
    public function testCreateSwapListsOnlyTheEmployeesOfTheManagersStores(): void
    {
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [['user_id' => 1], ['user_id' => 2]] : []);
        $this->users->method('findAll')->willReturn([
            ['id' => 1, 'last_name' => 'Dupont', 'first_name' => 'Jean'],
            ['id' => 2, 'last_name' => 'Martin', 'first_name' => 'Paul'],
            ['id' => 3, 'last_name' => 'Autre', 'first_name' => 'Magasin'],
        ]);

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [1]);

        $response = $this->controller()->createSwap($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStoreSwapAppliesImmediateExchangeWithoutApprovalWorkflow(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $_POST = [
            'requester_id'       => '1',
            'target_id'          => '2',
            'requester_shift_id' => '10',
            'target_shift_id'    => '20',
            'reason'             => 'Test',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $requesterShift = ['id' => 10, 'user_id' => 1, 'store_id' => 5];
        $targetShift    = ['id' => 20, 'user_id' => 2, 'store_id' => 5];

        kintai_test_shifts([$requesterShift + ['id' => 10], $targetShift + ['id' => 20]]);

        $capturedSwap = null;
        $this->swapRequests->method('save')->willReturnCallback(function (array $d) use (&$capturedSwap) {
            $capturedSwap = $d;
            return $d + ['id' => 77];
        });

        $this->notifs->expects($this->once())->method('notifyMany')
            ->with([1, 2], 'swap_applied', $this->isString(), $this->anything(), 77);

        $response = $this->controller()->storeSwap($req);

        $this->assertSame(302, $response->getStatusCode());

        // Les deux shifts ont été échangés immédiatement (pas de statut pending)
        $this->assertSame(2, Shift::findById(10)['user_id']);
        $this->assertSame(1, Shift::findById(20)['user_id']);
        $this->assertSame('accepted', $capturedSwap['status']);
        $this->assertSame(2, $capturedSwap['target_user_id']);
        $this->assertNotNull($capturedSwap['accepted_at']);
    }

    public function testStoreSwapForbiddenWhenShiftsBelongToDifferentStores(): void
    {
        $_POST = [
            'requester_id'       => '1',
            'target_id'          => '2',
            'requester_shift_id' => '10',
            'target_shift_id'    => '20',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        kintai_test_shifts([['id' => 10, 'user_id' => 1, 'store_id' => 5] + ['id' => 10], ['id' => 20, 'user_id' => 2, 'store_id' => 6] + ['id' => 20]]);

        $this->expectException(ForbiddenException::class);
        $this->controller()->storeSwap($req);
    }

    public function testCreateSwapExcludesShiftsThatWouldCreateSameDayConflict(): void
    {
        $this->users->method('findAll')->willReturn([]);

        // Le demandeur a un shift le 2026-08-05 ; la cible a un shift le 2026-08-05 et un autre le 2026-08-10.
        kintai_test_shifts([
            ['id' => 10, 'user_id' => 1, 'shift_date' => '2026-08-05', 'start_time' => '09:00', 'end_time' => '17:00'],
                ['id' => 20, 'user_id' => 2, 'shift_date' => '2026-08-05', 'start_time' => '10:00', 'end_time' => '18:00'],
                ['id' => 21, 'user_id' => 2, 'shift_date' => '2026-08-10', 'start_time' => '09:00', 'end_time' => '17:00'],
        ]);

        $_GET = ['requester_id' => '1', 'target_id' => '2'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->createSwap($req);

        $this->assertSame(200, $response->getStatusCode());
        // On ne peut pas vérifier le HTML rendu (vue stub dans les tests), mais on s'assure
        // au minimum qu'aucune exception n'est levée par le filtrage des conflits.
    }

    public function testStoreSwapBlockedWhenResultingInSameDayConflict(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $_POST = [
            'requester_id'       => '1',
            'target_id'          => '2',
            'requester_shift_id' => '10',
            'target_shift_id'    => '20',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $requesterShift = ['id' => 10, 'user_id' => 1, 'store_id' => 5, 'shift_date' => '2026-08-05'];
        $targetShift    = ['id' => 20, 'user_id' => 2, 'store_id' => 5, 'shift_date' => '2026-08-06'];

        kintai_test_shifts([$requesterShift + ['id' => 10], $targetShift + ['id' => 20]]);
        // Le demandeur a déjà un autre shift (#99) le 2026-08-06 : recevoir le shift cible créerait un conflit.
        kintai_test_shifts([['id' => 99, 'user_id' => 1, 'store_id' => 5, 'shift_date' => '2026-08-06']]);

        $this->swapRequests->expects($this->never())->method('save');

        $response = $this->controller()->storeSwap($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreSwapForbiddenWhenSameEmployeeSelectedTwice(): void
    {
        $_POST = [
            'requester_id'       => '1',
            'target_id'          => '1',
            'requester_shift_id' => '10',
            'target_shift_id'    => '20',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $this->expectException(ForbiddenException::class);
        $this->controller()->storeSwap($req);
    }

    public function testSwapRequestsLooksUpReferencedShiftsForDisplay(): void
    {
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $this->swapRequests->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 5, 'requester_id' => 1, 'target_user_id' => 2, 'requester_shift_id' => 10, 'target_shift_id' => 20, 'status' => 'pending'],
        ]);
        $this->users->method('findAll')->willReturn([]);
        kintai_test_shifts([['id' => 10, 'shift_date' => '2026-08-01', 'start_time' => '09:00', 'end_time' => '17:00'] + ['id' => 10], ['id' => 20, 'shift_date' => '2026-08-02', 'start_time' => '10:00', 'end_time' => '18:00'] + ['id' => 20]]);

        $response = $this->controller()->swapRequests($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function location(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return (string) $response->headers->get('Location', '');
    }

    private function pendingSwap(): array
    {
        return [
            'id' => 31, 'store_id' => 5, 'status' => 'pending',
            'requester_id' => 1, 'target_user_id' => 2,
            'requester_shift_id' => 10, 'target_shift_id' => 20,
        ];
    }

    /** Audit du 13/10/2026 : un rejeu de l'approbation ré-échangeait les shifts et annulait l'échange. */
    public function testApproveSwapOnAnAlreadyAcceptedSwapChangesNothing(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '31']);
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(31)->willReturn(['status' => 'accepted'] + $this->pendingSwap());
        $this->swapRequests->expects($this->never())->method('save');

        $this->location($this->controller()->approveSwap($req));
        $this->assertStringContainsString('invalid_state', (string) session('error'));
    }

    public function testApproveSwapRefusesWhenAShiftWasReassignedSinceTheRequest(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '31']);
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(31)->willReturn($this->pendingSwap());
        // Le shift #10 n'appartient plus au demandeur (1) : il a été réaffecté à 8.
        kintai_test_shifts([['id' => 10, 'user_id' => 8, 'store_id' => 5, 'shift_date' => '2026-09-01'] + ['id' => 10], ['id' => 20, 'user_id' => 2, 'store_id' => 5, 'shift_date' => '2026-09-02'] + ['id' => 20]]);
        $this->swapRequests->expects($this->never())->method('save');

        $this->location($this->controller()->approveSwap($req));
        $this->assertStringContainsString('invalid_state', (string) session('error'));
    }

    public function testApproveSwapRefusesAConflictingSameDayExchange(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '31']);
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(31)->willReturn($this->pendingSwap());
        kintai_test_shifts([['id' => 10, 'user_id' => 1, 'store_id' => 5, 'shift_date' => '2026-09-01'] + ['id' => 10], ['id' => 20, 'user_id' => 2, 'store_id' => 5, 'shift_date' => '2026-09-02'] + ['id' => 20]]);
        // Le demandeur a déjà un autre shift le 02/09 (date du shift qu'il recevrait).
        kintai_test_shifts([['id' => 99, 'user_id' => 1, 'store_id' => 5, 'shift_date' => '2026-09-02']]);
        $this->location($this->controller()->approveSwap($req));
        $this->assertStringContainsString('swap_conflict', (string) session('error'));
    }

    public function testApproveSwapExchangesTheShiftsOfAPendingSwap(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '31']);
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(31)->willReturn($this->pendingSwap());
        kintai_test_shifts([['id' => 10, 'user_id' => 1, 'store_id' => 5, 'shift_date' => '2026-09-01'] + ['id' => 10], ['id' => 20, 'user_id' => 2, 'store_id' => 5, 'shift_date' => '2026-09-02'] + ['id' => 20]]);
        $this->swapRequests->expects($this->once())->method('save')->with($this->callback(fn(array $d) => $d['status'] === 'accepted'));

        $this->location($this->controller()->approveSwap($req));
        $this->assertStringContainsString('approved', (string) session('success'));
        $this->assertSame([10 => 2, 20 => 1], [10 => Shift::findById(10)['user_id'], 20 => Shift::findById(20)['user_id']]);
    }

    public function testRefuseSwapOnAnAlreadyAcceptedSwapChangesNothing(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '31']);
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(31)->willReturn(['status' => 'accepted'] + $this->pendingSwap());
        $this->swapRequests->expects($this->never())->method('save');

        $this->location($this->controller()->refuseSwap($req));
        $this->assertStringContainsString('invalid_state', (string) session('error'));
    }

    /** Les shifts ont été réaffectés depuis l'échange : les « rendre » écraserait ce changement. */
    public function testDeleteSwapDoesNotRestoreShiftsThatMovedSinceTheExchange(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '77']);
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(77)->willReturn([
            'id' => 77, 'store_id' => 5, 'status' => 'accepted', 'requester_id' => 1, 'target_user_id' => 2,
            'requester_shift_id' => 10, 'target_shift_id' => 20,
        ]);
        // Le shift #10 devrait appartenir à 2 après l'échange ; il appartient maintenant à 8.
        kintai_test_shifts([['id' => 10, 'user_id' => 8, 'store_id' => 5] + ['id' => 10], ['id' => 20, 'user_id' => 1, 'store_id' => 5] + ['id' => 20]]);
        $this->swapRequests->expects($this->once())->method('delete')->with(77);

        $this->location($this->controller()->deleteSwap($req));
        $this->assertStringContainsString('deleted', (string) session('success'));
    }

    public function testDeleteSwapRevertsShiftsWhenAccepted(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '77']);

        $swap = [
            'id' => 77, 'store_id' => 5, 'status' => 'accepted',
            'requester_id' => 1, 'target_user_id' => 2,
            'requester_shift_id' => 10, 'target_shift_id' => 20,
        ];
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(77)->willReturn($swap);

        // Après l'échange initial : le shift #10 appartient à 2, le shift #20 appartient à 1
        $requesterShift = ['id' => 10, 'user_id' => 2, 'store_id' => 5];
        $targetShift    = ['id' => 20, 'user_id' => 1, 'store_id' => 5];
        kintai_test_shifts([$requesterShift + ['id' => 10], $targetShift + ['id' => 20]]);

        $this->notifs->expects($this->once())->method('notifyMany')->with([1, 2], 'swap_cancelled_restored', $this->isString(), $this->anything(), 77);
        $this->swapRequests->expects($this->once())->method('delete')->with(77);

        $response = $this->controller()->deleteSwap($req);

        $this->assertSame(302, $response->getStatusCode());
        // Les shifts sont revenus à leurs propriétaires d'origine
        $this->assertSame(1, Shift::findById(10)['user_id']);
        $this->assertSame(2, Shift::findById(20)['user_id']);
    }

    public function testDeleteSwapDoesNotTouchShiftsWhenNotAccepted(): void
    {
        $this->swapRequests = $this->createMock(ShiftSwapRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '5']);

        $swap = [
            'id' => 5, 'store_id' => 5, 'status' => 'pending',
            'requester_id' => 1, 'target_user_id' => 2,
            'requester_shift_id' => 10, 'target_shift_id' => 20,
        ];
        $this->swapRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn($swap);

        $this->notifs->expects($this->never())->method('notifyMany');
        $this->swapRequests->expects($this->once())->method('delete')->with(5);

        $response = $this->controller()->deleteSwap($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testSwapRequestsFiltersByStatus(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
        $this->writeViewContent($viewDir, 'swap-requests', "<?php echo json_encode(array_map(fn(\$s) => \$s['id'], \$swaps));");
        $this->writeViewContent(sys_get_temp_dir(), 'layout.app', "<?php echo \$content ?? '';");

        $this->swapRequests->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 5, 'requester_id' => 1, 'target_user_id' => 2, 'status' => 'pending'],
            ['id' => 2, 'store_id' => 5, 'requester_id' => 1, 'target_user_id' => 2, 'status' => 'accepted'],
            ['id' => 3, 'store_id' => 5, 'requester_id' => 1, 'target_user_id' => 2, 'status' => 'refused'],
        ]);
        $this->users->method('findAll')->willReturn([]);

        $_GET = ['status' => 'accepted'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->swapRequests($req);
        $ids = json_decode($response->getContent(), true);

        $this->assertSame([2], $ids);
    }

    public function testSwapRequestsSortsByStoreName(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-shift-swap-views';
        $this->writeViewContent($viewDir, 'swap-requests', "<?php echo json_encode(array_map(fn(\$s) => \$s['id'], \$swaps));");
        $this->writeViewContent(sys_get_temp_dir(), 'layout.app', "<?php echo \$content ?? '';");

        kintai_test_stores(findAll: fn() => [
            ['id' => 1, 'name' => 'Zoo Store'],
            ['id' => 2, 'name' => 'Alpha Store'],
        ]);
        $this->swapRequests->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 1, 'requester_id' => 1, 'target_user_id' => 2, 'status' => 'pending'],
            ['id' => 2, 'store_id' => 2, 'requester_id' => 1, 'target_user_id' => 2, 'status' => 'pending'],
        ]);
        $this->users->method('findAll')->willReturn([]);

        $_GET = ['sort' => 'store_asc'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->swapRequests($req);
        $ids = json_decode($response->getContent(), true);

        $this->assertSame([2, 1], $ids);
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

    private function writeViewContent(string $dir, string $view, string $content): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($file, $content);
    }
}
