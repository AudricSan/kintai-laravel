<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\Requests;

use kintai\Core\Auth\PermissionService;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Tests\Support\FakeBundleManagerFactory;
use kintai\Tests\Support\FakeClaimsProvider;
use kintai\Tests\Support\FakePendingTimeoffProvider;
use kintai\UI\Controller\Web\Requests\AdminRequestsController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Page de résumé "Demandes" (admin.requests) : agrège congés, échanges de
 * shifts et candidatures bourse aux shifts en attente, lus à travers les
 * fournisseurs déclarés par les bundles (PendingRequests). Chaque section n'est
 * présente que si son bundle est actif ET que l'utilisateur a la permission
 * "*.view" correspondante — sinon elle reste absente de la page. Les
 * congés, échanges et candidatures viennent des fournisseurs déclarés par timeoff, shift-swap et shift-claim : sans eux, leur
 * section disparaît proprement, sans faire planter le contrôleur.
 */
final class AdminRequestsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->ensureViewFile(
            'requests.index',
            // Une entrée par section affichée : clé du fournisseur => demandes (identifiant + colonnes).
            "<?php \$o = []; foreach (\$sections as \$s) { \$o[\$s['key']] = array_map(fn(\$r) => ['id' => \$r->id, 'columns' => \$r->columns], \$s['requests']); } echo json_encode(\$o);"
        );
        $this->ensureViewFile('layout.app', "<?php echo \$content ?? '';");
    }

    protected function tearDown(): void
    {
        // Réinitialise le singleton Container pour ne pas propager le
        // BundleManager injecté par enableBundles() vers d'autres tests.
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $instance->setValue(null, null);
    }

    /** @param list<array<string, mixed>> $timeoff demandes de congé fournies par timeoff, s'il est actif */
    private function enableBundles(array $slugs, array $timeoff = []): void
    {
        $pending = in_array('timeoff', $slugs, true) ? ['timeoff' => [new FakePendingTimeoffProvider($timeoff)]] : [];
        $manager = FakeBundleManagerFactory::withActiveSlugs($slugs, [], $pending);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    private function buildController(bool $isAdmin): AdminRequestsController
    {
        $usersRepo = $this->createStub(UserRepositoryInterface::class);
        $usersRepo->method('findAll')->willReturn([
            ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'A'],
            ['id' => 2, 'first_name' => 'Bob', 'last_name' => 'B'],
        ]);

        $storesRepo = $this->createStub(StoreRepositoryInterface::class);
        $storesRepo->method('findAll')->willReturn([['id' => 1, 'name' => 'Store 1']]);

        $storeUsersRepo = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsersRepo->method('findByStore')->willReturn([]);

        $shiftsRepo = $this->createStub(ShiftRepositoryInterface::class);
        $shiftsRepo->method('findById')->willReturn(['id' => 10, 'shift_date' => '2026-08-10', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);

        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn($isAdmin ? [] : [
            ['id' => 1, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => 1],
        ]);
        $roles = $this->createMock(RoleRepositoryInterface::class);
        $roles->method('findById')->with(5)->willReturn(['id' => 5, 'is_system' => 0]);
        $roles->method('getPermissions')->with(5)->willReturn(['timeoff.view', 'swaps.view']);
        $permissions = new PermissionService($roleAssignments, $roles);

        // Les demandes sont lues à travers les fournisseurs (PendingRequests), qui prennent leurs dépôts dans le
        // conteneur.
        $container = Container::getInstance();
        $container->instance(ShiftRepositoryInterface::class, $shiftsRepo);
        $container->instance(StoreRepositoryInterface::class, $storesRepo);

        return new AdminRequestsController(
            new ViewRenderer(sys_get_temp_dir()),
            $usersRepo,
            $storesRepo,
            $storeUsersRepo,
            $permissions,
        );
    }

    private function request(bool $isAdmin, ?array $managedStoreIds = null): Request
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => $isAdmin ? 1 : 0]);
        $req->setAttribute('managed_store_ids', $managedStoreIds);
        return $req;
    }

    public function testOnlyTimeoffPresentWhenSwapAndClaimBundlesDeclareNothing(): void
    {
        $this->enableBundles(['timeoff', 'shift-swap']);

        $controller = $this->buildController(true);
        $response   = $controller->index($this->request(true));
        $data       = json_decode($response->body(), true);

        $this->assertSame([], $data['timeoff']);
        $this->assertArrayNotHasKey('swaps', $data, 'shift-swap actif sans fournisseur déclaré');
        $this->assertArrayNotHasKey('open_shifts', $data);
    }

    public function testAllSectionsNullWhenBundlesDisabled(): void
    {
        $this->enableBundles([]);

        $controller = $this->buildController(true);
        $response   = $controller->index($this->request(true));
        $data       = json_decode($response->body(), true);

        $this->assertSame([], $data, 'aucune section');
    }

    public function testTimeoffFilteredToPendingAndScopedToManagedStore(): void
    {
        $timeoff = [
            ['id' => 1, 'store_id' => 1, 'status' => 'pending', 'user_id' => 1],
            ['id' => 2, 'store_id' => 1, 'status' => 'approved', 'user_id' => 1],
            ['id' => 3, 'store_id' => 2, 'status' => 'pending', 'user_id' => 2],
        ];
        $this->enableBundles(['timeoff', 'shift-swap'], $timeoff);
        $controller = $this->buildController(false);
        $response   = $controller->index($this->request(false, [1]));
        $data       = json_decode($response->body(), true);

        $this->assertCount(1, $data['timeoff']);
        $this->assertSame(1, $data['timeoff'][0]['id']);
        $this->assertArrayNotHasKey('open_shifts', $data, 'pas de permission open_shifts.view');
    }

    public function testClaimsPresentAndEnrichedWithShiftInfoWhenBundleEnabled(): void
    {
        // Candidatures en attente déclarées par shift-claim (fournisseur du contrat 1.9).
        $manager = FakeBundleManagerFactory::withActiveSlugs(['shift-swap', 'shift-claim'], [], ['shift-claim' => [
            new FakeClaimsProvider([FakeClaimsProvider::claim(1, 1, 1, '2026-08-10', '09:00:00', '17:00:00')]),
        ]]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));

        $controller = $this->buildController(true);
        $response   = $controller->index($this->request(true));
        $data       = json_decode($response->body(), true);

        $this->assertCount(1, $data['open_shifts']);
        $columns = array_column($data['open_shifts'][0]['columns'], 'value', 'label');
        $this->assertSame('2026-08-10', $columns['date']);
        $this->assertSame(1, $columns['applicant']);
        $this->assertSame('09:00:00', $columns['start']);
    }

    private function ensureViewFile(string $view, string $content): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, $content);
    }
}
