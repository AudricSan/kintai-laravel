<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use App\Auth\CredentialRevoker;
use App\Auth\OwnerGuard;
use App\Auth\PermissionService;
use App\Auth\UserTargetGuard;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Models\ApiToken;
use App\Models\AppSetting;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\LicenseClientService;
use Illuminate\Support\Facades\Log;
use App\Services\PlanLimitService;
use App\Services\StoreService;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\IcalTokenController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\UserController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Auth\UserAnonymizer;
use PHPUnit\Framework\TestCase;

/**
 * Régression (audit du 03/10/2026) : l'API du cœur ne bornait pas les routes `/{id}` au
 * magasin de la ressource — ApiPermissionMiddleware ne voit qu'un `store_id` fourni par le
 * client, et PermissionService::can(…, null) répond « oui » dès qu'une affectation accorde
 * la clé quelque part. Un gérant du magasin 5 pouvait donc modifier le magasin 6, réécrire
 * le mot de passe d'un Owner, ou écraser n'importe quelle ligne par un POST portant un `id`.
 */
final class ApiStoreScopeTest extends TestCase
{
    use \Tests\Support\ApiTokenExpectations;

    private const OWNER   = 1;
    private const MANAGER = 2;
    private const STORE_A = 5; // magasin du gérant
    private const STORE_B = 6; // autre magasin

    private PermissionService $permissions;

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
        kintai_test_assignments(findByUser: fn(int $uid): array => match ($uid) {
            self::OWNER   => [['id' => 1, 'user_id' => self::OWNER, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]],
            self::MANAGER => [['id' => 2, 'user_id' => self::MANAGER, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::STORE_A]],
            default       => [],
        }, findByScope: fn() => [
            ['id' => 1, 'user_id' => self::OWNER, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id): array => match ($id) {
            10      => ['id' => 10, 'is_system' => 1],
            default => ['id' => 20, 'is_system' => 0],
        }, getPermissions: static fn() => [
            'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete',
            'employees.view', 'employees.update', 'employees.delete',
            'stores.view', 'stores.update',
        ], getGlobalPermissionKeys: static fn() => []);
        $this->permissions = new PermissionService();
    }

    protected function tearDown(): void
    {
    }

    private function request(int $userId, array $params = [], ?array $json = null): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        $req->setRouteParams($params);
        if ($json !== null) {
            $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($json));
        }
        return $req;
    }

    private function userController(?CredentialRevoker $revoker = null): UserController
    {
        $planLimits = new PlanLimitService(
            new LicenseClientService(['base_url' => '', 'api_key' => '']),
        );
        return new UserController(new AuditLogger(), $planLimits, $this->permissions, new OwnerGuard($this->permissions), $revoker);
    }

    // ---------------------------------------------------------------- shifts

    public function testManagerCannotUpdateShiftOfAnotherStore(): void
    {
        kintai_test_shifts([['id' => 9, 'store_id' => self::STORE_B]]);
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        // Le client déclare son propre magasin dans le corps pour passer la porte du middleware.
        $controller->update($this->request(self::MANAGER, ['id' => '9'], ['store_id' => self::STORE_A]));
    }

    public function testManagerCannotDeleteShiftOfAnotherStore(): void
    {
        kintai_test_shifts([['id' => 9, 'store_id' => self::STORE_B]]);
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->destroy($this->request(self::MANAGER, ['id' => '9']));
    }

    public function testManagerCannotMoveOwnShiftToAnotherStore(): void
    {
        kintai_test_shifts([['id' => 3, 'store_id' => self::STORE_A]]);
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->update($this->request(self::MANAGER, ['id' => '3'], ['store_id' => self::STORE_B]));
    }

    public function testManagerCanUpdateShiftOfOwnStore(): void
    {
        kintai_test_shifts([['id' => 3, 'store_id' => self::STORE_A]]);
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $response = $controller->update($this->request(self::MANAGER, ['id' => '3'], ['notes' => 'x']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShiftCreationIgnoresIdFromBody(): void
    {
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $response = $controller->store($this->request(self::MANAGER, [], ['id' => 9, 'store_id' => self::STORE_A, 'shift_date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '17:00']));

        $saved = $this->savedShift(null);
        $this->assertNotSame(9, $saved['id'], 'l\'identifiant du corps est ignoré');
        $this->assertSame(self::STORE_A, $saved['store_id']);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testShiftCreationRequiresStoreId(): void
    {
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $this->expectException(ValidationException::class);
        $controller->store($this->request(self::MANAGER, [], ['note' => 'sans magasin']));
    }

    public function testShiftCreationForAnotherStoreIsForbidden(): void
    {
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->store($this->request(self::MANAGER, [], ['store_id' => self::STORE_B, 'shift_date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '17:00']));
    }

    public function testShiftIndexIsRestrictedToManagedStores(): void
    {
        kintai_test_shifts([
            ['id' => 1, 'user_id' => 77, 'store_id' => self::STORE_A],
            ['id' => 2, 'user_id' => 77, 'store_id' => self::STORE_B],
        ]);
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $response = $controller->index($this->requestWithQuery(self::MANAGER, ['user_id' => '77']));

        $ids = array_column(json_decode($response->getContent(), true)['data'], 'id');
        $this->assertSame([1], $ids);
    }

    private function requestWithQuery(int $userId, array $query): Request
    {
        $_GET = $query;
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        return $req;
    }

    // --------------------------------------------------------- availabilities

    public function testAvailabilityCreationIgnoresIdAndChecksStore(): void
    {
        $controller = new AvailabilityController(new AuditLogger(), $this->permissions);

        try {
            $controller->store($this->request(self::MANAGER, [], ['id' => 4, 'store_id' => self::STORE_B, 'user_id' => 30, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']));
            $this->fail('disponibilité créée dans un magasin non géré');
        } catch (ForbiddenException) {
            $this->assertSame(0, \App\Models\Availability::count());
        }
    }

    // ----------------------------------------------------------------- stores

    public function testManagerCannotUpdateAnotherStore(): void
    {
        kintai_test_stores(findById: fn() => ['id' => self::STORE_B]);
        $controller = new StoreController($this->createStub(StoreService::class), new AuditLogger(), $this->permissions);

        $this->expectException(ForbiddenException::class);
        $controller->update($this->request(self::MANAGER, ['id' => (string) self::STORE_B], ['name' => 'piraté']));
    }

    // ------------------------------------------------------------------ users

    public function testManagerCannotChangeOwnerPassword(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::OWNER, 'email' => 'owner@example.com']);
        // L'Owner est (aussi) membre du magasin du gérant : seule la protection Owner doit le bloquer.
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::STORE_A]]);
        $this->expectException(ForbiddenException::class);
        $this->userController()->update($this->request(self::MANAGER, ['id' => (string) self::OWNER], ['password' => 'motdepasse-pirate']));
    }

    public function testManagerCannotUpdateEmployeeOfAnotherStore(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 30 ? ['id' => 30] : null);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 30 ? [['store_id' => self::STORE_B]] : []);
        $this->expectException(ForbiddenException::class);
        $this->userController()->update($this->request(self::MANAGER, ['id' => '30'], ['last_name' => 'X']));
    }

    public function testUpdateHashesPasswordNeverStoresRawHashAndRevokesCredentials(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 31 ? ['id' => 31, 'is_active' => 1] : null);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 31 ? [['store_id' => self::STORE_A]] : []);
        kintai_test_remember_token(31);
        $this->expectApiTokensRevoked(31);
        $revoker = new CredentialRevoker();

        $response = $this->userController($revoker)->update($this->request(
            self::MANAGER,
            ['id' => '31'],
            ['password' => 'nouveau-mot-de-passe', 'password_hash' => 'forgé', 'id' => 99]
        ));

        $this->assertArrayNotHasKey('password_hash', json_decode($response->getContent(), true));
        $this->assertNull(\App\Models\User::query()->find(31)?->remember_token, 'jeton « rester connecté » révoqué');
    }

    public function testRawPasswordHashCannotBeInjectedOnUpdate(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 31 ? ['id' => 31] : null);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 31 ? [['store_id' => self::STORE_A]] : []);
        $this->userController()->update($this->request(self::MANAGER, ['id' => '31'], ['password_hash' => 'forgé', 'last_name' => 'Nouveau']));

        $saved = \App\Models\User::findById(31);
        $this->assertNotSame('forgé', $saved['password_hash']);
        $this->assertSame('Nouveau', $saved['last_name']);
    }

    public function testUserCreationIgnoresIdAndSetsDefaultPassword(): void
    {
        kintai_test_users(countActive: fn() => 1);
        $response = $this->userController()->store($this->request(self::OWNER, [], ['id' => 1, 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B']));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayNotHasKey('password_hash', json_decode($response->getContent(), true));
    }

    public function testShowAndIndexNeverExposePasswordHash(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 31 ? ['id' => 31, 'password_hash' => 'secret'] : null, findAll: fn() => [['id' => 31, 'password_hash' => 'secret']]);
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::STORE_A]]);
        $show = $this->userController()->show($this->request(self::OWNER, ['id' => '31']));
        $this->assertArrayNotHasKey('password_hash', json_decode($show->getContent(), true));

        $index = $this->userController()->index($this->request(self::OWNER));
        $this->assertArrayNotHasKey('password_hash', json_decode($index->getContent(), true)['data'][0]);
    }

    public function testUserIndexIsRestrictedToManagedStoreMembers(): void
    {
        kintai_test_users(findAll: fn() => [['id' => 31], ['id' => 32], ['id' => self::MANAGER]]);
        kintai_test_store_users(findByStore: fn() => [['user_id' => 31]]);
        $response = $this->userController()->index($this->request(self::MANAGER));

        $ids = array_column(json_decode($response->getContent(), true)['data'], 'id');
        $this->assertEqualsCanonicalizing([31, self::MANAGER], $ids);
    }

    // ------------------------------------------------------------ ical tokens

    public function testManagerCannotReadIcalTokensOfAnotherStoreEmployee(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 30 ? ['id' => 30] : null);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 30 ? [['store_id' => self::STORE_B]] : []);
        $controller = new IcalTokenController(
            new UserTargetGuard($this->permissions),
        );

        $this->expectException(ForbiddenException::class);
        $controller->index($this->request(self::MANAGER, ['user_id' => '30']));
    }

    // ------------------------------------------------- validation (audit M9)

    /** Les champs calculés d'un shift viennent du serveur : ils manquaient dans un POST client (erreur SQL). */
    public function testShiftCreationDerivesTimingAndIgnoresClientValues(): void
    {
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $controller->store($this->request(self::MANAGER, [], [
            'store_id' => self::STORE_A, 'shift_date' => '2026-10-31', 'start_time' => '22:00', 'end_time' => '04:00',
            'duration_minutes' => 99999, 'starts_at' => 'forgé', 'ical_sequence' => 50, 'created_by' => self::OWNER,
        ]));

        $saved = $this->savedShift(null);
        $this->assertSame(360, $saved['duration_minutes']);
        $this->assertSame(1, $saved['cross_midnight']);
        $this->assertSame('2026-10-31 22:00:00', $saved['starts_at']);
        $this->assertSame('2026-11-01 04:00:00', $saved['ends_at']);
        $this->assertSame(0, (int) $saved['ical_sequence'], 'la séquence iCal vient du serveur');
        $this->assertNotSame(self::OWNER, $saved['created_by'], 'l\'auteur ne vient pas du client');
    }

    public function testShiftUpdateRecomputesTimingFromTheStoredValues(): void
    {
        kintai_test_shifts([[
            'id' => 3, 'store_id' => self::STORE_A, 'shift_date' => '2026-10-20', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
        ]]);
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $controller->update($this->request(self::MANAGER, ['id' => '3'], ['end_time' => '18:30']));

        $saved = $this->savedShift(3);
        $this->assertSame(570, $saved['duration_minutes']);
        $this->assertSame('2026-10-20 18:30:00', $saved['ends_at']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidShifts(): array
    {
        $base = ['store_id' => self::STORE_A, 'shift_date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '17:00'];
        return [
            'corps vide'      => [[]],
            'date impossible' => [['shift_date' => '2026-13-01'] + $base],
            'heure invalide'  => [['start_time' => '9h'] + $base],
            'pause negative'  => [['pause_minutes' => -10] + $base],
            'magasin texte'   => [['store_id' => 'A'] + $base],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidShifts')]
    public function testShiftCreationRefusesInvalidBodies(array $body): void
    {
        $controller = new ShiftController(new AuditLogger(), $this->permissions);

        $this->expectException(ValidationException::class);
        $controller->store($this->request(self::MANAGER, [], $body));
    }

    public function testAvailabilityRefusesAnInvalidDayOfWeek(): void
    {
        $controller = new AvailabilityController(new AuditLogger(), $this->permissions);

        try {
            $controller->store($this->request(self::MANAGER, [], ['store_id' => self::STORE_A, 'user_id' => 30, 'day_of_week' => 7, 'start_time' => '09:00', 'end_time' => '17:00']));
            $this->fail('jour de la semaine invalide accepté');
        } catch (ValidationException) {
            $this->assertSame(0, \App\Models\Availability::count());
        }
    }

    // ------------------------------------------- suppression = anonymisation (audit M8)

    public function testDestroyAnonymizesTheAccountInsteadOfDeletingIt(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 31 ? ['id' => 31, 'is_active' => 1] : null);
        kintai_test_store_users(findByUser: fn(int $id) => $id === 31 ? [['store_id' => self::STORE_A]] : []);
        $planLimits = new PlanLimitService(
            new LicenseClientService(['base_url' => '', 'api_key' => '']),
        );
        $revoker = new CredentialRevoker();
        $controller = new UserController(
            new AuditLogger(), $planLimits, $this->permissions,
            new OwnerGuard($this->permissions), $revoker,
            new UserAnonymizer($revoker, sys_get_temp_dir()),
        );

        $response = $controller->destroy($this->request(self::MANAGER, ['id' => '31']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull(\App\Models\User::findById(31)['deleted_at'], 'compte anonymisé, jamais effacé');
    }

    public function testDeletedAccountsAreNoLongerListed(): void
    {
        kintai_test_users(findAll: fn() => [
            ['id' => 31, 'deleted_at' => null],
            ['id' => 32, 'deleted_at' => '2026-10-16 09:00:00'],
        ]);
        $response = $this->userController()->index($this->request(self::OWNER));

        $this->assertSame([31], array_column(json_decode($response->getContent(), true)['data'], 'id'));
    }

    // ------------------------------------------- actions sur soi-même (audit M13)

    public function testAManagerCannotDeleteTheirOwnAccountThroughTheApi(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::MANAGER, 'is_active' => 1]);
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::STORE_A]]);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->userController()->destroy($this->request(self::MANAGER, ['id' => (string) self::MANAGER]));
    }

    public function testAManagerCannotDeactivateThemselvesThroughTheApi(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::MANAGER, 'is_active' => 1, 'email' => 'm@kintai.test']);
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::STORE_A]]);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->userController()->update($this->request(self::MANAGER, ['id' => (string) self::MANAGER], ['is_active' => false]));
    }

    public function testAManagerCanStillEditTheirOwnProfileFieldsThroughTheApi(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::MANAGER, 'is_active' => 1, 'email' => 'm@kintai.test']);
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::STORE_A]]);
        $response = $this->userController()->update($this->request(self::MANAGER, ['id' => (string) self::MANAGER], ['phone' => '090-0000-0000']));

        $this->assertSame(200, $response->getStatusCode());
    }
}
