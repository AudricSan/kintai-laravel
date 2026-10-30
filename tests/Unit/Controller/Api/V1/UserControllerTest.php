<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use kintai\Contract\Event\EmployeeCreated;
use App\Bundles\Bundle;
use App\BundleManager;
use App\Extension\Events;
use App\Extension\ExtensionRegistry;
use App\Models\StoreUser;
use Tests\Support\ApiOwnerAuth;
use App\Exceptions\PlanLimitExceededException;
use App\Auth\OwnerGuard;
use App\Models\AppSetting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\LicenseClientService;
use Illuminate\Support\Facades\Log;
use App\Services\PlanLimitService;
use App\Http\Controllers\Api\V1\UserController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class UserControllerTest extends TestCase
{
    private ?UserController $controller = null;

    protected function setUp(): void
    {
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): UserController
    {
        if ($this->controller === null) {
            $this->controller = new UserController(
                new AuditLogger(),
                new PlanLimitService(
                    new LicenseClientService(['base_url' => '', 'api_key' => '']),
                ),
                ApiOwnerAuth::permissions($this),
                new OwnerGuard(ApiOwnerAuth::permissions($this)),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
    }

    private function makeJsonRequest(array $body): Request
    {
        $req = ApiOwnerAuth::owner(Request::capture());
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
        return $req;
    }

    public function testStoreCreatesUserWhenBelowFreePlanLimit(): void
    {
        kintai_test_users(countActive: fn() => 14);
        $response = $this->controller()->store($this->makeJsonRequest(['email' => 'new@example.com', 'first_name' => 'Nouvel', 'last_name' => 'Employé']));

        $this->assertSame(201, $response->getStatusCode());
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
            new AuditLogger(),
            new PlanLimitService(
                new LicenseClientService(['base_url' => '', 'api_key' => '']),
            ),
            ApiOwnerAuth::permissions($this),
            new OwnerGuard(ApiOwnerAuth::permissions($this)),
            events: new Events(new ExtensionRegistry($manager)),
        );
        kintai_test_users(countActive: fn() => 1);
        $controller->store($this->makeJsonRequest(['email' => 'new@example.com', 'first_name' => 'Nouvel', 'last_name' => 'Employé']));

        $this->assertCount(1, $received);
        $this->assertSame((int) \App\Models\User::query()->max('id'), $received[0]->userId());
        $this->assertNull($received[0]->storeId);
        $this->assertSame(ApiOwnerAuth::OWNER_ID, $received[0]->createdBy);
        $this->assertSame('api', $received[0]->source);
        $this->assertArrayNotHasKey('password_hash', $received[0]->user);
    }

    public function testAnOwnerCannotDeleteThemselvesThroughTheApi(): void
    {
        kintai_test_users(findById: fn() => ['id' => ApiOwnerAuth::OWNER_ID, 'is_active' => 1, 'deleted_at' => null]);
        $req = ApiOwnerAuth::owner(Request::capture());
        $req->setRouteParams(['id' => (string) ApiOwnerAuth::OWNER_ID]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->destroy($req);
    }

    public function testAnOwnerCannotDeactivateThemselvesThroughTheApi(): void
    {
        kintai_test_users(findById: fn() => ['id' => ApiOwnerAuth::OWNER_ID, 'is_active' => 1, 'deleted_at' => null]);
        $req = $this->makeJsonRequest(['is_active' => 0]);
        $req->setRouteParams(['id' => (string) ApiOwnerAuth::OWNER_ID]);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->controller()->update($req);
    }

    public function testStoreThrowsWhenFreePlanEmployeeLimitReached(): void
    {
        kintai_test_users(countActive: fn() => 15);
        $this->expectException(PlanLimitExceededException::class);
        $this->controller()->store($this->makeJsonRequest(['email' => 'new@example.com']));
    }

    // -------------------------------------------------------------------------
    // Validation du corps (audit du 13/10/2026, M9)
    // -------------------------------------------------------------------------

    /** Un corps vide finissait en erreur SQL (500) : c'est maintenant un 422 détaillé, sans écriture. */
    public function testStoreWithAnEmptyBodyIsRefusedWithTheMissingFields(): void
    {
        kintai_test_users(countActive: fn() => 1);
        try {
            $this->controller()->store($this->makeJsonRequest([]));
            $this->fail('422 attendu');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['email', 'first_name', 'last_name'], array_keys($e->errors));
        }
    }

    public function testStoreRefusesADuplicateEmail(): void
    {
        kintai_test_users(findByEmail: fn() => ['id' => 3, 'email' => 'pris@example.com'], countActive: fn() => 1);
        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeJsonRequest(['email' => 'pris@example.com', 'first_name' => 'A', 'last_name' => 'B']));
    }

    public function testStoreKeepsOnlyKnownFieldsAndDerivesTheDisplayName(): void
    {
        kintai_test_users(countActive: fn() => 1);
        $saved = null;
        $this->controller()->store($this->makeJsonRequest([
            'email' => 'n@example.com', 'first_name' => 'Aiko', 'last_name' => 'Sato', 'employee_code' => 'e-12',
            'deleted_at' => '2020-01-01', 'last_login_at' => 'x', 'avatar_path' => '../../etc/passwd',
        ]));

        $saved = \App\Models\User::query()->orderByDesc('id')->first()?->toArray();
        $this->assertSame('Sato Aiko', $saved['display_name']);
        $this->assertSame('E-12', $saved['employee_code']);
        foreach (['deleted_at', 'last_login_at', 'avatar_path'] as $field) {
            $this->assertEmpty($saved[$field], "champ ignoré : $field");
        }
    }

    public function testUpdateWithNothingToChangeIsRefused(): void
    {
        kintai_test_users(findById: fn() => ['id' => 4, 'email' => 'a@example.com']);
        $req = $this->makeJsonRequest(['created_at' => '2000-01-01']);
        $req->setRouteParams(['id' => '4']);

        $this->expectException(ValidationException::class);
        $this->controller()->update($req);
    }

    /** Audit M1 : couleur de compte non hexadécimale refusée en création comme en modification. */
    public function testStoreRejectsAnInvalidColor(): void
    {
        kintai_test_users(countActive: fn() => 1);
        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeJsonRequest(['email' => 'new@example.com', 'first_name' => 'A', 'last_name' => 'B', 'color' => 'red;position:fixed']));
    }

    public function testUpdateRejectsAnInvalidColor(): void
    {
        kintai_test_users(findById: fn() => ['id' => 4, 'email' => 'a@example.com']);
        $req = $this->makeJsonRequest(['color' => 'url(javascript:x)']);
        $req->setRouteParams(['id' => '4']);

        $this->expectException(ValidationException::class);
        $this->controller()->update($req);
    }
}
