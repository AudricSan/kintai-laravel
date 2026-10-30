<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use kintai\Contract\Event\EmployeeCreated;
use App\Auth\OwnerGuard;
use App\Auth\PermissionService;
use App\Bundles\Bundle;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Extension\Events;
use App\Extension\ExtensionRegistry;
use App\Models\AppSetting;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\LicenseClientService;
use Illuminate\Support\Facades\Log;
use App\Services\PlanLimitService;
use App\Services\RoleAssignmentSyncService;
use App\Http\Controllers\Staff\AdminUserController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
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
        kintai_test_users(findByEmail: fn() => null, findByEmployeeCode: fn() => null);
        kintai_test_roles(findById: fn() => ['id' => 2, 'name' => 'Staff', 'is_system' => 0, 'is_managing' => 0]);
        kintai_test_role_table([['id' => 2, 'name' => 'Staff', 'is_system' => 0, 'is_managing' => 0]]);
        $permissions = new PermissionService();

        return new AdminUserController(
            kintai_test_views(sys_get_temp_dir()),
            new AuditLogger(),
            new RoleAssignmentSyncService(),
            $permissions,
            new PlanLimitService(new LicenseClientService(['base_url' => '', 'api_key' => ''])),
            new OwnerGuard($permissions),
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
        $request = Request::capture();
        $request->attributes->set('auth_user', ['id' => 1, 'is_admin' => false]);
        $request->attributes->set('managed_store_ids', null);
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

        $this->assertContains($response->getStatusCode(), [200, 302], $source);
        $this->assertSame([], $this->received);
    }

    #[DataProvider('creationPaths')]
    public function testListeningBundleReceivesTheEvent(string $method, string $source): void
    {
        $this->controller($this->events(true))->{$method}(self::request());

        $this->assertCount(1, $this->received);
        $event = $this->received[0];
        $this->assertSame((int) \App\Models\User::query()->max('id'), $event->userId());
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
        $this->savedUsers = [\App\Models\User::query()->orderByDesc('id')->first()?->toArray()];

        $this->assertNotEmpty($this->savedUsers, $source);
        $this->assertTrue(password_verify('0000', (string) $this->savedUsers[0]['password_hash']), $source);
    }
}
