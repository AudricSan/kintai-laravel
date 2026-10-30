<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff\Controllers\Web;


use kintai\Bundles\Installed\TimeOff\Controllers\Web\AdminTimeoffController;
use App\Exceptions\ForbiddenException;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use kintai\Bundles\Installed\TimeOff\Repositories\TimeoffRequestRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AdminTimeoffControllerTest extends TestCase
{
    private TimeoffRequestRepositoryInterface&Stub $timeoffRequests;
    private UserRepositoryInterface&Stub $users;
    private StoreRepositoryInterface&Stub $stores;
    private NotificationService&Stub $notifs;
    private ?AdminTimeoffController $controller = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
        $this->ensureViewFile($viewDir, 'timeoff-form');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->timeoffRequests = $this->createStub(TimeoffRequestRepositoryInterface::class);
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->notifs = $this->createStub(NotificationService::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminTimeoffController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('timeoff', $viewDir);
            $this->controller = new AdminTimeoffController(
                $view,
                $this->timeoffRequests,
                new AuditLogger(),
                $this->notifs,
                $this->stores,
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

    public function testCreateTimeoffRendersFormForAdmin(): void
    {
        $this->users->method('findAll')->willReturn([
            ['id' => 1, 'last_name' => 'Dupont', 'first_name' => 'Jean'],
        ]);

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->createTimeoff($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStoreTimeoffForEmployeeCreatesApprovedRequestVisibleOnCalendar(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $_POST = [
            'user_id'    => '5',
            'type'       => 'vacation',
            'start_date' => '2026-08-01',
            'end_date'   => '2026-08-03',
            'reason'     => 'Congés imposés',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'display_name' => 'Alice']);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 5 ? [['store_id' => 3, 'user_id' => 5]] : []);
        $captured = null;
        $this->timeoffRequests->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 99];
        });

        $this->notifs->expects($this->once())->method('notify')
            ->with(5, 'timeoff_approved', $this->isString(), $this->anything(), 99, '/employee/timeoff?highlight=99');

        $response = $this->controller()->storeTimeoffForEmployee($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($captured);
        $this->assertSame('approved', $captured['status']);
        $this->assertSame(5, $captured['user_id']);
        $this->assertSame(3, $captured['store_id']);
        $this->assertSame('2026-08-01', $captured['start_date']);
        $this->assertSame('2026-08-03', $captured['end_date']);
        // Colonnes de traitement de la migration (reviewed_* n'existent pas sur une installation neuve : erreur 500).
        $this->assertSame(1, $captured['processed_by']);
        $this->assertArrayHasKey('processed_at', $captured);
        $this->assertArrayNotHasKey('reviewed_by', $captured);
    }

    public function testStoreTimeoffForEmployeeForbiddenWhenEmployeeNotManaged(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $_POST = [
            'user_id'    => '5',
            'type'       => 'vacation',
            'start_date' => '2026-08-01',
            'end_date'   => '2026-08-01',
        ];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [1, 2]);
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5]);
        kintai_test_store_users(findByStore: fn() => []);
        $this->expectException(ForbiddenException::class);
        $this->controller()->storeTimeoffForEmployee($req);
    }

    /** Audit du 13/10/2026 : approuver/refuser ne doit agir que sur une demande en attente. */
    public function testApproveTimeoffIgnoresARequestThatIsNotPending(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '5']);
        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 1, 'user_id' => 9, 'status' => 'cancelled']);
        $this->timeoffRequests->expects($this->never())->method('save');
        $this->notifs->expects($this->never())->method('notify');

        $response = $this->controller()->approveTimeoff($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('invalid_state', (string) session('error'));
    }

    public function testRefuseTimeoffIgnoresARequestThatIsNotPending(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '5']);
        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 1, 'user_id' => 9, 'status' => 'approved']);
        $this->timeoffRequests->expects($this->never())->method('save');

        $this->location($this->controller()->refuseTimeoff($req));
        $this->assertStringContainsString('invalid_state', (string) session('error'));
    }

    public function testApproveTimeoffStillWorksOnAPendingRequest(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '5']);
        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 1, 'user_id' => 9, 'status' => 'pending', 'type' => 'sick', 'start_date' => '2026-08-01', 'end_date' => '2026-08-01']);
        $this->timeoffRequests->expects($this->once())->method('save')->with($this->callback(fn(array $d) => $d['status'] === 'approved'));
        $this->notifs->expects($this->once())->method('notify');

        $this->location($this->controller()->approveTimeoff($req));
        $this->assertStringContainsString('approved', (string) session('success'));
    }

    public function testStoreTimeoffForEmployeeRejectsEndBeforeStart(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $_POST = ['user_id' => '5', 'type' => 'vacation', 'start_date' => '2026-08-05', 'end_date' => '2026-08-01'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 1]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5]);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 5 ? [['store_id' => 3, 'user_id' => 5]] : []);
        $this->timeoffRequests->expects($this->never())->method('save');

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->storeTimeoffForEmployee($req);
    }

    /** Avant : toujours la première adhésion, donc 403 pour le gérant du second magasin. */
    public function testStoreTimeoffForEmployeePicksTheStoreTheManagerManages(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $_POST = ['user_id' => '5', 'type' => 'vacation', 'start_date' => '2026-08-01'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', [4]);
        $req->attributes->set('auth_user', ['id' => 1]);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5]);
        kintai_test_store_users(findByStore: fn() => [['user_id' => 5]], findByUser: fn(int $id) => $id === 5 ? [
            ['store_id' => 3, 'user_id' => 5], ['store_id' => 4, 'user_id' => 5],
        ] : []);
        $this->timeoffRequests->expects($this->once())->method('save')->with($this->callback(
            fn(array $d) => $d['store_id'] === 4
        ))->willReturn(['id' => 12]);

        $this->assertSame(302, $this->controller()->storeTimeoffForEmployee($req)->getStatusCode());
    }

    private function location(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return (string) $response->headers->get('Location', '');
    }

    public function testDeleteTimeoffRemovesRecordRegardlessOfStatus(): void
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->setRouteParams(['id' => '42']);

        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(42)->willReturn([
            'id' => 42, 'store_id' => 3, 'user_id' => 5, 'status' => 'approved',
        ]);
        $this->timeoffRequests->expects($this->once())->method('delete')->with(42);

        $response = $this->controller()->deleteTimeoff($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testTimeoffFiltersByTypeAndStatus(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
        $this->writeViewContent($viewDir, 'timeoff', "<?php echo json_encode(array_map(fn(\$r) => \$r['id'], \$requests));");
        $this->writeViewContent(sys_get_temp_dir(), 'layout.app', "<?php echo \$content ?? '';");

        $this->timeoffRequests->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 1, 'user_id' => 1, 'type' => 'vacation', 'status' => 'pending', 'start_date' => '2026-08-01'],
            ['id' => 2, 'store_id' => 1, 'user_id' => 1, 'type' => 'sick', 'status' => 'approved', 'start_date' => '2026-08-02'],
            ['id' => 3, 'store_id' => 1, 'user_id' => 1, 'type' => 'vacation', 'status' => 'approved', 'start_date' => '2026-08-03'],
        ]);

        $_GET = ['type' => 'vacation', 'status' => 'approved'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->timeoff($req);
        $ids = json_decode($response->getContent(), true);

        $this->assertSame([3], $ids);
    }

    public function testTimeoffWithoutFiltersShowsAllStatuses(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
        $this->writeViewContent($viewDir, 'timeoff', "<?php echo json_encode(array_map(fn(\$r) => \$r['id'], \$requests));");
        $this->writeViewContent(sys_get_temp_dir(), 'layout.app', "<?php echo \$content ?? '';");

        $this->timeoffRequests->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 1, 'user_id' => 1, 'type' => 'vacation', 'status' => 'pending', 'start_date' => '2026-08-01'],
            ['id' => 2, 'store_id' => 1, 'user_id' => 1, 'type' => 'sick', 'status' => 'approved', 'start_date' => '2026-08-02'],
        ]);

        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->timeoff($req);
        $ids = json_decode($response->getContent(), true);

        $this->assertCount(2, $ids);
    }

    public function testTimeoffSortsByStoreName(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-timeoff-views';
        $this->writeViewContent($viewDir, 'timeoff', "<?php echo json_encode(array_map(fn(\$r) => \$r['id'], \$requests));");
        $this->writeViewContent(sys_get_temp_dir(), 'layout.app', "<?php echo \$content ?? '';");

        $this->stores->method('findAll')->willReturn([
            ['id' => 1, 'name' => 'Zoo Store'],
            ['id' => 2, 'name' => 'Alpha Store'],
        ]);
        $this->timeoffRequests->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 1, 'user_id' => 1, 'type' => 'vacation', 'status' => 'pending', 'start_date' => '2026-08-01'],
            ['id' => 2, 'store_id' => 2, 'user_id' => 1, 'type' => 'vacation', 'status' => 'pending', 'start_date' => '2026-08-02'],
        ]);

        $_GET = ['sort' => 'store_asc'];
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);

        $response = $this->controller()->timeoff($req);
        $ids = json_decode($response->getContent(), true);

        // "Alpha Store" (id 2) doit passer avant "Zoo Store" (id 1) en tri croissant par nom de store.
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

    /** @return array<string, mixed>|null Demande enregistrée par la décision */
    private function decide(string $action): ?array
    {
        $this->timeoffRequests = $this->createMock(TimeoffRequestRepositoryInterface::class);
        $req = Request::capture();
        $req->attributes->set('managed_store_ids', null);
        $req->attributes->set('auth_user', ['id' => 3]);
        $req->setRouteParams(['id' => '5']);
        $this->timeoffRequests->expects($this->atLeastOnce())->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 1, 'user_id' => 9, 'status' => 'pending', 'type' => 'sick', 'start_date' => '2026-08-01', 'end_date' => '2026-08-01']);
        $saved = null;
        $this->timeoffRequests->method('save')->willReturnCallback(function (array $d) use (&$saved): array {
            $saved = $d;
            return $d;
        });

        $this->controller()->{$action}($req);
        return $saved;
    }

    public function testApprovingRecordsWhoHandledTheRequestAndWhen(): void
    {
        $saved = $this->decide('approveTimeoff');

        $this->assertSame('approved', $saved['status']);
        $this->assertSame(3, $saved['processed_by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $saved['processed_at']);
    }

    public function testRefusingRecordsWhoHandledTheRequestAndWhen(): void
    {
        $saved = $this->decide('refuseTimeoff');

        $this->assertSame('refused', $saved['status']);
        $this->assertSame(3, $saved['processed_by']);
        $this->assertNotEmpty($saved['processed_at']);
    }
}
