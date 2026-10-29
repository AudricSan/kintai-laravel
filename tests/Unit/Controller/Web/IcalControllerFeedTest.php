<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Exceptions\NotFoundException;
use Illuminate\Container\Container;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
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
use Illuminate\Http\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\IcalService;
use kintai\Core\Services\Log;
use kintai\Core\Services\TranslationService;
use kintai\UI\Controller\Web\IcalController;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/** Audit n°7 : le flux iCal d'un compte désactivé ou ayant quitté le magasin ne doit plus être servi. */
final class IcalControllerFeedTest extends TestCase
{
    private IcalTokenRepositoryInterface&Stub $tokens;
    private ShiftRepositoryInterface&Stub $shifts;
    private StoreRepositoryInterface&Stub $stores;
    private UserRepositoryInterface&Stub $users;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private IcalController $controller;

    protected function setUp(): void
    {
        $this->tokens     = $this->createStub(IcalTokenRepositoryInterface::class);
        $this->shifts     = $this->createStub(ShiftRepositoryInterface::class);
        $this->stores     = $this->createStub(StoreRepositoryInterface::class);
        $this->users      = $this->createStub(UserRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);

        $this->tokens->method('findByToken')->willReturnCallback(fn($a0) => $a0 === 'tok' ? ['id' => 1, 'user_id' => 9, 'store_id' => 3] : null);
        $this->stores->method('findById')->willReturnCallback(fn($a0) => $a0 === 3 ? ['id' => 3, 'code' => 'ST'] : null);
        $this->shifts->method('findByUser')->willReturn([]);
        $this->shifts->method('findRecentDeletionsByUserAndStore')->willReturn([]);

        // __() lit le TranslationService du Container (voir IcalServiceTest).
        $translations = new TranslationService(
            new JsonTranslationRepository(dirname(__DIR__, 4) . '/lang', dirname(__DIR__, 4) . '/app/Bundles'),
            new JsonLanguageRepository(dirname(__DIR__, 4) . '/lang'),
        );
        Container::getInstance()->instance(TranslationService::class, $translations);

        $this->controller = new IcalController(
            new IcalService($translations),
            $this->tokens,
            $this->shifts,
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->stores,
            $this->users,
            new AuthService(
                $this->users,
                $this->storeUsers,
                $this->stores,
                $this->createStub(RoleRepositoryInterface::class),
                $this->createStub(RoleAssignmentRepositoryInterface::class),
                $this->createStub(RememberTokenRepositoryInterface::class),
            ),
            new AuditLogger(),
            $this->storeUsers,
        );
    }

    protected function tearDown(): void
    {
        Log::reset();
        // Retire le TranslationService lié dans setUp() : il fausserait les tests qui attendent des clés brutes.
        Container::getInstance()->offsetUnset(TranslationService::class);
    }

    private function request(): Request
    {
        $req = Request::capture();
        $req->setRouteParams(['token' => 'tok', 'store_id' => '3']);
        return $req;
    }

    public function testFeedRefusesADeactivatedAccount(): void
    {
        $this->users->method('findById')->willReturnCallback(fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 0] : null);
        $this->storeUsers->method('findMembership')->willReturn(['id' => 1]);

        $this->expectException(NotFoundException::class);
        $this->controller->feed($this->request());
    }

    public function testFeedRefusesAnEmployeeWhoLeftTheStore(): void
    {
        $this->users->method('findById')->willReturnCallback(fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 1] : null);
        $this->storeUsers->method('findMembership')->willReturnCallback(fn($a0, $a1) => $a0 === 3 && $a1 === 9 ? null : null);

        $this->expectException(NotFoundException::class);
        $this->controller->feed($this->request());
    }

    public function testFeedServesAnActiveMember(): void
    {
        $this->users->method('findById')->willReturnCallback(fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 1] : null);
        $this->storeUsers->method('findMembership')->willReturnCallback(fn($a0, $a1) => $a0 === 3 && $a1 === 9 ? ['id' => 1] : null);

        $this->assertSame(200, $this->controller->feed($this->request())->getStatusCode());
    }

    /** L'ETag ignore DTSTAMP : un client dont l'agenda n'a pas changé reçoit un 304 au lieu du flux complet. */
    public function testFeedAnswers304WhenTheCalendarHasNotChanged(): void
    {
        $this->users->method('findById')->willReturnCallback(fn($a0) => $a0 === 9 ? ['id' => 9, 'is_active' => 1] : null);
        $this->storeUsers->method('findMembership')->willReturn(['id' => 1]);

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
