<?php

declare(strict_types=1);

namespace Tests\Bundles\Timeclock\Controllers\Web;


use kintai\Bundles\Installed\Timeclock\Controllers\Web\EmployeeTimeclockController;
use Tests\Support\TestContainer;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

final class EmployeeTimeclockControllerTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeclocks', 'timeclock'];
    }

    private ?EmployeeTimeclockController $controller = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $viewDir = sys_get_temp_dir() . '/kintai-timeclock-views';
        $this->ensureViewFile($viewDir, 'employee-timeclock');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->resetRows();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): EmployeeTimeclockController
    {
        if ($this->controller === null) {
            $viewDir = sys_get_temp_dir() . '/kintai-timeclock-views';
            $view = kintai_test_views(sys_get_temp_dir());
            $view->addNamespace('timeclock', $viewDir);
            $this->controller = new EmployeeTimeclockController(
                $view,
                new AuditLogger(),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    public function testTimeclockRendersWhenFeatureEnabled(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        $this->seedRows([]);
        $this->seedRows([]);
        $this->seedRows([]);

        $response = $this->controller()->timeclock($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTimeclockRedirectsWhenFeatureDisabled(): void
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        kintai_test_store_features(5, ['shifts']);

        $response = $this->controller()->timeclock($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testClockInCreatesEntry(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        $this->seedRows([]);
        $this->expectSaved(fn(array $data) => $data['user_id'] === 1 && $data['store_id'] === 5 && $data['clock_out_time'] === null);

        $response = $this->controller()->clockIn($req);

        $this->assertSame(201, $response->getStatusCode());
    }

    /** Un employé multi-magasins pointe sur le magasin qu'il choisit (avant : toujours le premier). */
    public function testClockInUsesTheChosenStoreWhenMember(): void
    {
        $this->resetRows();
        $_POST['store_id'] = '7';
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5], ['store_id' => 7]] : []);
        $this->seedRows([]);
        $this->expectSaved(fn(array $data) => $data['store_id'] === 7);

        $this->assertSame(201, $this->controller()->clockIn($req)->getStatusCode());
    }

    public function testClockInRefusesAStoreTheEmployeeDoesNotBelongTo(): void
    {
        $this->resetRows();
        $_POST['store_id'] = '99';
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        $this->seedRows([]);
        $this->expectNoChange();

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->clockIn($req);
    }

    /** Avant : la ligne était créée avec store_id = 0 pour un employé sans magasin. */
    public function testClockInRefusesAnEmployeeWithoutAnyStore(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [] : []);
        $this->seedRows([]);
        $this->expectNoChange();

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->controller()->clockIn($req);
    }

    public function testClockInRejectsWhenAlreadyActive(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        $this->seedRows([['id' => 9, 'user_id' => 1, 'clock_out_time' => null]]);
        $this->expectNoChange();

        $this->expectException(ConflictException::class);
        $this->controller()->clockIn($req);
    }

    public function testClockOutComputesDuration(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->seedRows([[
            'id' => 9, 'user_id' => 1, 'store_id' => 5, 'clock_in_time' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        ]]);
        $this->expectSaved(fn(array $data) => $data['id'] === 9 && $data['duration_minutes'] >= 29 && $data['duration_minutes'] <= 31);

        $response = $this->controller()->clockOut($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testClockOutThrowsWhenNoActiveEntry(): void
    {
        $this->resetRows();
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->seedRows([]);

        $this->expectException(NotFoundException::class);
        $this->controller()->clockOut($req);
    }

    /** Un pointage synchronisé après coup (hors-ligne) doit garder l'heure réelle du clic, pas l'heure de synchro. */
    public function testClockInUsesClientTimeWhenWithinTrustWindow(): void
    {
        $this->resetRows();
        $clientTime = (new \DateTimeImmutable('-2 hours'))->format('c');
        $_POST['client_time'] = $clientTime;

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        $this->seedRows([]);

        $expected = (new \DateTimeImmutable($clientTime))->format('Y-m-d H:i:s');
        $this->expectSaved(fn(array $data) => $data['clock_in_time'] === $expected && $data['shift_date'] === substr($expected, 0, 10));

        $response = $this->controller()->clockIn($req);

        $this->assertSame(201, $response->getStatusCode());
    }

    /** Un client_time trop ancien (dérive excessive) est ignoré au profit de l'heure serveur. */
    public function testClockInIgnoresClientTimeOutsideTrustWindow(): void
    {
        $this->resetRows();
        $_POST['client_time'] = (new \DateTimeImmutable('-2 days'))->format('c');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        kintai_test_store_users(findByUser: fn(int $id) => $id === 1 ? [['store_id' => 5]] : []);
        $this->seedRows([]);

        $today = date('Y-m-d');
        $this->expectSaved(fn(array $data) => $data['shift_date'] === $today);

        $this->controller()->clockIn($req);
    }

    /** La durée d'un pointage hors-ligne se calcule sur l'heure réelle de sortie, pas l'heure de synchro. */
    public function testClockOutUsesClientTimeForDuration(): void
    {
        $this->resetRows();
        $_POST['client_time'] = (new \DateTimeImmutable('-30 minutes'))->format('c');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->seedRows([[
            'id' => 9, 'user_id' => 1, 'store_id' => 5, 'clock_in_time' => (new \DateTimeImmutable('-90 minutes'))->format('Y-m-d H:i:s'),
        ]]);
        $this->expectSaved(fn(array $data) => $data['duration_minutes'] >= 59 && $data['duration_minutes'] <= 61);

        $response = $this->controller()->clockOut($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    /** Une sortie hors-ligne rejouée avant son entrée (désordre de synchro) retombe sur l'heure serveur. */
    public function testClockOutFallsBackToServerTimeWhenClientTimeBeforeClockIn(): void
    {
        $this->resetRows();
        $_POST['client_time'] = (new \DateTimeImmutable('-2 hours'))->format('c');

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1]);

        $this->seedRows([[
            'id' => 9, 'user_id' => 1, 'store_id' => 5, 'clock_in_time' => (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        ]]);
        $this->expectSaved(fn(array $data) => $data['duration_minutes'] >= 0);

        $this->controller()->clockOut($req);
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
}
