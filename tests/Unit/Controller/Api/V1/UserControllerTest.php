<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Contract\Event\EmployeeCreated;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\Events;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Tests\Support\ApiOwnerAuth;
use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\Log;
use kintai\Core\Services\PlanLimitService;
use kintai\UI\Controller\Api\V1\UserController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class UserControllerTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private ?UserController $controller = null;

    protected function setUp(): void
    {
        $this->users = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): UserController
    {
        if ($this->controller === null) {
            $stores = $this->createStub(StoreRepositoryInterface::class);
            $this->controller = new UserController(
                $this->users,
                new AuditLogger(),
                new PlanLimitService(
                    $stores,
                    $this->users,
                    new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
                ),
                ApiOwnerAuth::permissions($this),
                $this->createStub(StoreUserRepositoryInterface::class),
                new OwnerGuard(ApiOwnerAuth::permissions($this), $this->users),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    private function makeJsonRequest(array $body): Request
    {
        $req = ApiOwnerAuth::owner(new Request());
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setAccessible(true);
        $ref->setValue($req, $body);
        return $req;
    }

    public function testStoreCreatesUserWhenBelowFreePlanLimit(): void
    {
        $this->users->method('countActive')->willReturn(14);
        $this->users->method('save')->willReturn(['id' => 1, 'email' => 'new@example.com']);

        $response = $this->controller()->store($this->makeJsonRequest(['email' => 'new@example.com', 'first_name' => 'Nouvel', 'last_name' => 'Employé']));

        $this->assertSame(201, $response->status());
    }

    /** Contrat 1.13 : les bundles qui écoutent EmployeeCreated l'apprennent, sans magasin ni secret. */
    public function testStoreAnnouncesTheNewEmployeeToBundles(): void
    {
        $received = [];
        $bundle = new class extends Bundle {
            public ?\Closure $listener = null;

            public function __construct() {}

            public function getName(): string
            {
                return 'acme';
            }

            public function register(): void {}

            public function eventListeners(): array
            {
                return [EmployeeCreated::class => $this->listener];
            }
        };
        $bundle->listener = function (EmployeeCreated $event) use (&$received): void {
            $received[] = $event;
        };
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['acme' => $bundle]);
        $controller = new UserController(
            $this->users,
            new AuditLogger(),
            new PlanLimitService(
                $this->createStub(StoreRepositoryInterface::class),
                $this->users,
                new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
            ),
            ApiOwnerAuth::permissions($this),
            $this->createStub(StoreUserRepositoryInterface::class),
            new OwnerGuard(ApiOwnerAuth::permissions($this), $this->users),
            events: new Events(new ExtensionRegistry($manager)),
        );
        $this->users->method('countActive')->willReturn(1);
        $this->users->method('save')->willReturn(['id' => 12, 'email' => 'new@example.com', 'password_hash' => 'hash']);

        $controller->store($this->makeJsonRequest(['email' => 'new@example.com', 'first_name' => 'Nouvel', 'last_name' => 'Employé']));

        $this->assertCount(1, $received);
        $this->assertSame(12, $received[0]->userId());
        $this->assertNull($received[0]->storeId);
        $this->assertSame(ApiOwnerAuth::OWNER_ID, $received[0]->createdBy);
        $this->assertSame('api', $received[0]->source);
        $this->assertArrayNotHasKey('password_hash', $received[0]->user);
    }

    public function testAnOwnerCannotDeleteThemselvesThroughTheApi(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => ApiOwnerAuth::OWNER_ID, 'is_active' => 1, 'deleted_at' => null]);
        $this->users->expects($this->never())->method('delete');

        $req = ApiOwnerAuth::owner(new Request());
        $req->setRouteParams(['id' => (string) ApiOwnerAuth::OWNER_ID]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->destroy($req);
    }

    public function testAnOwnerCannotDeactivateThemselvesThroughTheApi(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => ApiOwnerAuth::OWNER_ID, 'is_active' => 1, 'deleted_at' => null]);
        $this->users->expects($this->never())->method('save');

        $req = $this->makeJsonRequest(['is_active' => 0]);
        $req->setRouteParams(['id' => (string) ApiOwnerAuth::OWNER_ID]);

        $this->expectException(\kintai\Core\Exceptions\ForbiddenException::class);
        $this->controller()->update($req);
    }

    public function testStoreThrowsWhenFreePlanEmployeeLimitReached(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countActive')->willReturn(15);
        $this->users->expects($this->never())->method('save');

        $this->expectException(PlanLimitExceededException::class);
        $this->controller()->store($this->makeJsonRequest(['email' => 'new@example.com']));
    }

    // -------------------------------------------------------------------------
    // Validation du corps (audit du 13/10/2026, M9)
    // -------------------------------------------------------------------------

    /** Un corps vide finissait en erreur SQL (500) : c'est maintenant un 422 détaillé, sans écriture. */
    public function testStoreWithAnEmptyBodyIsRefusedWithTheMissingFields(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countActive')->willReturn(1);
        $this->users->expects($this->never())->method('save');

        try {
            $this->controller()->store($this->makeJsonRequest([]));
            $this->fail('422 attendu');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['email', 'first_name', 'last_name'], array_keys($e->errors));
        }
    }

    public function testStoreRefusesADuplicateEmail(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countActive')->willReturn(1);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('pris@example.com')->willReturn(['id' => 3]);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeJsonRequest(['email' => 'pris@example.com', 'first_name' => 'A', 'last_name' => 'B']));
    }

    public function testStoreKeepsOnlyKnownFieldsAndDerivesTheDisplayName(): void
    {
        $this->users->method('countActive')->willReturn(1);
        $saved = null;
        $this->users->method('save')->willReturnCallback(function (array $d) use (&$saved): array {
            $saved = $d;
            return $d + ['id' => 8];
        });

        $this->controller()->store($this->makeJsonRequest([
            'email' => 'n@example.com', 'first_name' => 'Aiko', 'last_name' => 'Sato', 'employee_code' => 'e-12',
            'deleted_at' => '2020-01-01', 'last_login_at' => 'x', 'avatar_path' => '../../etc/passwd',
        ]));

        $this->assertSame('Sato Aiko', $saved['display_name']);
        $this->assertSame('E-12', $saved['employee_code']);
        foreach (['deleted_at', 'last_login_at', 'avatar_path'] as $field) {
            $this->assertArrayNotHasKey($field, $saved);
        }
    }

    public function testUpdateWithNothingToChangeIsRefused(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 4, 'email' => 'a@example.com']);
        $this->users->expects($this->never())->method('save');

        $req = $this->makeJsonRequest(['created_at' => '2000-01-01']);
        $req->setRouteParams(['id' => '4']);

        $this->expectException(ValidationException::class);
        $this->controller()->update($req);
    }

    /** Audit M1 : couleur de compte non hexadécimale refusée en création comme en modification. */
    public function testStoreRejectsAnInvalidColor(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('countActive')->willReturn(1);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeJsonRequest(['email' => 'new@example.com', 'first_name' => 'A', 'last_name' => 'B', 'color' => 'red;position:fixed']));
    }

    public function testUpdateRejectsAnInvalidColor(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 4, 'email' => 'a@example.com']);
        $this->users->expects($this->never())->method('save');

        $req = $this->makeJsonRequest(['color' => 'url(javascript:x)']);
        $req->setRouteParams(['id' => '4']);

        $this->expectException(ValidationException::class);
        $this->controller()->update($req);
    }
}
