<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Exceptions\NotFoundException;
use Illuminate\Container\Container;
use App\Models\IcalToken;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\IcalService;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\Translator;
use App\Http\Controllers\IcalController;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/** Audit n°7 : le flux iCal d'un compte désactivé ou ayant quitté le magasin ne doit plus être servi. */
final class IcalControllerFeedTest extends TestCase
{
    private IcalController $controller;

    protected function setUp(): void
    {

        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(9);
        \Illuminate\Support\Facades\DB::table('stores')->insert(['id' => 3, 'name' => 'Store', 'code' => 'ST']);
        IcalToken::put(['user_id' => 9, 'store_id' => 3, 'token' => 'tok', 'created_at' => date('Y-m-d H:i:s')]);
        kintai_test_stores(findById: fn($a0) => $a0 === 3 ? ['id' => 3, 'code' => 'ST'] : null);
        kintai_test_shifts([]);

        // __() lit le TranslationService du Container (voir IcalServiceTest).
        $translations = kintai_test_real_translator();

        $this->controller = new IcalController(
            new IcalService($translations),
            new AuthService(
            ),
            new AuditLogger(),
        );
    }

    protected function tearDown(): void
    {
        // Retire le TranslationService lié dans setUp() : il fausserait les tests qui attendent des clés brutes.
        kintai_test_translator_reset();
    }

    private function request(): Request
    {
        $req = Request::capture();
        $req->setRouteParams(['token' => 'tok', 'store_id' => '3']);
        return $req;
    }

    public function testFeedRefusesADeactivatedAccount(): void
    {
        kintai_test_users(findById: fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 0] : null);
        kintai_test_store_users(findMembership: fn() => ['id' => 1]);
        $this->expectException(NotFoundException::class);
        $this->controller->feed($this->request());
    }

    public function testFeedRefusesAnEmployeeWhoLeftTheStore(): void
    {
        kintai_test_users(findById: fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 1] : null);
        kintai_test_store_users(findMembership: fn($a0, $a1) => $a0 === 3 && $a1 === 9 ? null : null);
        $this->expectException(NotFoundException::class);
        $this->controller->feed($this->request());
    }

    public function testFeedServesAnActiveMember(): void
    {
        kintai_test_users(findById: fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 1] : null);
        kintai_test_store_users(findMembership: fn($a0, $a1) => $a0 === 3 && $a1 === 9 ? ['id' => 1] : null);
        $this->assertSame(200, $this->controller->feed($this->request())->getStatusCode());
    }

    /** L'ETag ignore DTSTAMP : un client dont l'agenda n'a pas changé reçoit un 304 au lieu du flux complet. */
    public function testFeedAnswers304WhenTheCalendarHasNotChanged(): void
    {
        kintai_test_users(findById: fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 1] : null);
        kintai_test_store_users(findMembership: fn() => ['id' => 1]);
        $first = $this->controller->feed($this->request());
        $etag = $first->headers->get('ETag') ?? '';
        $this->assertNotSame('', $etag);

        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        try {
            $request = $this->request();
            $second = $this->controller->feed($request);
        } finally {
            unset($_SERVER['HTTP_IF_NONE_MATCH']);
        }

        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame('', $second->getContent());
        $this->assertSame(200, $this->controller->feed($this->request())->getStatusCode(), 'sans If-None-Match : flux complet');
    }
}
