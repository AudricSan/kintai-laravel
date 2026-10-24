<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Contract\Event\EmployeeCreated;
use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\Events;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\Log;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\UI\Controller\Web\Staff\AdminUserController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Création d'un employé (formulaire et import Excel) : le Core annonce EmployeeCreated aux bundles (contrat 1.13) ; le
 * rapport d'embauche est l'affaire du bundle hiring-report, qui l'écoute.
 */
final class AdminUserControllerEmployeeCreatedTest extends TestCase
{
    /** @var list<array<string, mixed>> comptes enregistrés */
    private array $savedUsers = [];

    /** @var list<EmployeeCreated> */
    private array $received = [];

    protected function setUp(): void
    {
        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->createStub(LogRepositoryInterface::class));
        Log::setContainer($container);
        $this->received = [];
    }

    private function events(bool $hiringBundleListens): Events
    {
        $bundle = new class extends Bundle {
            public ?\Closure $listener = null;

            public function __construct() {}

            public function getName(): string
            {
                return 'hiring-report';
            }

            public function register(): void {}

            public function eventListeners(): array
            {
                return $this->listener !== null ? [EmployeeCreated::class => $this->listener] : [];
            }
        };
        if ($hiringBundleListens) {
            $bundle->listener = function (EmployeeCreated $event): void {
                $this->received[] = $event;
            };
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['hiring-report' => $bundle]);

        return new Events(new ExtensionRegistry($manager));
    }

    private function controller(Events $events): AdminUserController
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn(null);
        $users->method('findByEmployeeCode')->willReturn(null);
        $users->method('save')->willReturnCallback(function (array $d): array {
            $this->savedUsers[] = $d;
            return ['id' => 10, 'password_hash' => 'hash'] + $d;
        });
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('save')->willReturnArgument(0);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 2, 'name' => 'Staff', 'is_system' => 0, 'is_managing' => 0]);
        $roles->method('findAll')->willReturn([['id' => 2, 'name' => 'Staff', 'is_system' => 0, 'is_managing' => 0]]);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $permissions = new PermissionService($roleAssignments, $roles);

        return new AdminUserController(
            new ViewRenderer(sys_get_temp_dir()),
            $users,
            $stores,
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $storeUsers,
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            new RoleAssignmentSyncService($roles, $roleAssignments),
            $permissions,
            new PlanLimitService($stores, $users, new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => ''])),
            new OwnerGuard($permissions, $users),
            events: $events,
        );
    }

    private static function request(): Request
    {
        $_POST = [
            'display_name' => 'Sato Hanako', 'first_name' => 'Hanako', 'last_name' => 'Sato', 'email' => 'sato@example.test',
            'store_id' => '3', 'role_id' => '2', 'store_role_id' => '2',
            'furigana_last_name' => 'サトウ', 'furigana_first_name' => 'ハナコ', 'phone' => '0312345678',
        ];
        $request = new Request();
        $request->setAttribute('auth_user', ['id' => 1, 'is_admin' => false]);
        $request->setAttribute('managed_store_ids', null);
        return $request;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function creationPaths(): array
    {
        return ['formulaire' => ['storeUser', 'form'], 'import Excel' => ['quickCreateUser', 'excel_import']];
    }

    #[DataProvider('creationPaths')]
    public function testWithoutListeningBundleNothingElseHappens(string $method, string $source): void
    {
        $response = $this->controller($this->events(false))->{$method}(self::request());

        $this->assertContains($response->status(), [200, 302], $source);
        $this->assertSame([], $this->received);
    }

    #[DataProvider('creationPaths')]
    public function testListeningBundleReceivesTheEvent(string $method, string $source): void
    {
        $this->controller($this->events(true))->{$method}(self::request());

        $this->assertCount(1, $this->received);
        $event = $this->received[0];
        $this->assertSame(10, $event->userId());
        $this->assertSame(3, $event->storeId);
        $this->assertSame(1, $event->createdBy);
        $this->assertSame($source, $event->source);
        $this->assertSame('サトウ', $event->user['furigana_last_name']);
        $this->assertArrayNotHasKey('password_hash', $event->user);
    }

    /**
     * Audit n°8 : un employé créé sans mot de passe reçoit « 0000 » (signalé comme à changer), plus nom+prénom+code
     * employé, devinable par un collègue.
     */
    #[DataProvider('creationPaths')]
    public function testAnEmployeeCreatedWithoutPasswordGetsTheDefaultOne(string $method, string $source): void
    {
        $this->controller($this->events(false))->{$method}(self::request());

        $this->assertNotEmpty($this->savedUsers, $source);
        $this->assertTrue(password_verify('0000', (string) $this->savedUsers[0]['password_hash']), $source);
    }
}
