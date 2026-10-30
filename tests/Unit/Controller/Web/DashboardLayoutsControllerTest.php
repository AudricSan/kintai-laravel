<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Models\RoleDashboardLayout;
use App\Models\Role;
use App\Models\UserDashboardLayout;
use App\Models\UserDashboardPref;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\DashboardLayoutResolver;
use App\Http\Controllers\DashboardLayoutsController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Mises en page nommées : chacun ne touche qu'aux siennes, 5 au plus, noms uniques ; défaut par rôle réservé aux Owners. */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DashboardLayoutsControllerTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;
    private DashboardLayoutsController $controller;

    protected function setUp(): void
    {
        session()->flush();
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(1);
        kintai_test_user(7);
        kintai_test_roles(findById: fn(int $id): ?array => $id === 2 ? ['id' => 2, 'name' => 'Manager'] : null);
        $resolver = new DashboardLayoutResolver(
            $this->createStub(RoleAssignmentRepositoryInterface::class),
        );
        $this->controller = new DashboardLayoutsController($resolver, new AuditLogger());
    }

    protected function tearDown(): void
    {
        $_POST = [];
        session()->flush();
    }

    private function request(array $post, array $user = ['id' => 7]): Request
    {
        $_POST = $post;
        $req = Request::capture();
        $req->attributes->set('auth_user', $user);
        return $req;
    }

    private function flash(): string
    {
        return implode('|', kintai_test_flash());
    }

    /** Enregistre une mise en page nommée de cet utilisateur et renvoie son identifiant. */
    private function stored(int $userId = 7, string $type = 'admin', string $name = 'Matin', bool $active = false): int
    {
        $id = UserDashboardLayout::createActive($userId, $type, $name, ['order' => ['shifts_today'], 'widths' => []]);
        if (!$active) {
            UserDashboardLayout::where('id', $id)->update(['is_active' => false]);
        }

        return $id;
    }

    public function testCreateSnapshotsTheCurrentLayoutAndMakesItActive(): void
    {
        $current = ['order' => ['kpi_counters'], 'widths' => ['kpi_counters' => 4]];
        $this->seedLayout(7, 'admin', $current);
        $this->expectLayout(7, 'admin', $current);

        $response = $this->controller->manageManager($this->request(['action' => 'create', 'name' => '  Matin ']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(__('dashboard_layouts_saved'), $this->flash());
        $saved = UserDashboardLayout::forUser(7, 'admin');
        $this->assertSame(['Matin'], array_column($saved, 'name'));
        $this->assertTrue($saved[0]['is_active']);
        $this->assertSame($current, $saved[0]['layout']);
    }
    public function testCreateIsRefusedBeyondFiveLayoutsOrWithADuplicateOrEmptyName(): void
    {
        foreach (range(1, 5) as $i) {
            $this->stored(name: 'L' . $i);
        }

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => 'Sixième']));
        $this->assertSame(__('dashboard_layouts_limit'), $this->flash());

        UserDashboardLayout::where('name', 'L5')->delete();
        $this->controller->manageManager($this->request(['action' => 'create', 'name' => 'l1']));
        $this->assertSame(__('dashboard_layouts_name_taken'), $this->flash(), 'noms uniques sans tenir compte de la casse');

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => '   ']));
        $this->assertSame(__('dashboard_layouts_name_invalid'), $this->flash());

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => str_repeat('x', 61)]));
        $this->assertSame(__('dashboard_layouts_name_invalid'), $this->flash());
        $this->assertCount(4, UserDashboardLayout::forUser(7, 'admin'), 'aucune mise en page créée');
    }
    public function testActivateCopiesTheLayoutIntoTheCurrentOne(): void
    {
        $id = $this->stored();
        $this->expectLayout(7, 'admin', ['order' => ['shifts_today'], 'widths' => []]);

        $this->controller->manageManager($this->request(['action' => 'activate', 'id' => (string) $id]));

        $this->assertTrue(UserDashboardLayout::describe($id)['is_active']);
    }
    public function testNobodyTouchesSomeoneElsesLayoutOrAnotherDashboardsLayout(): void
    {
        $others   = $this->stored(userId: 99);
        $employee = $this->stored(type: 'employee');
        $this->expectNoPrefsWrite();

        foreach (['activate', 'delete', 'rename'] as $action) {
            foreach ([$others, $employee, 404] as $id) {
                $this->controller->manageManager($this->request(['action' => $action, 'id' => (string) $id, 'name' => 'Autre']));
                $this->assertSame(__('dashboard_layouts_not_found'), $this->flash(), "$action #$id");
            }
        }
        $this->assertSame('Matin', UserDashboardLayout::describe($others)['name']);
        $this->assertNotNull(UserDashboardLayout::describe($employee));
    }
    public function testRenameAndDeleteOwnLayout(): void
    {
        $id = $this->stored(active: true);

        $this->controller->manageManager($this->request(['action' => 'rename', 'id' => (string) $id, 'name' => 'Soir']));
        $this->assertSame('Soir', UserDashboardLayout::describe($id)['name']);

        $this->controller->manageManager($this->request(['action' => 'delete', 'id' => (string) $id]));
        $this->assertNull(UserDashboardLayout::describe($id));
    }
    public function testEmployeeDashboardUsesItsOwnType(): void
    {
        $id = $this->stored(type: 'employee');

        $this->controller->manageEmployee($this->request(['action' => 'activate', 'id' => (string) $id]));

        $this->assertSame(['order' => ['shifts_today'], 'widths' => []], \App\Models\UserDashboardPref::getLayout(7, 'employee'));
        $this->assertNull(\App\Models\UserDashboardPref::getLayout(7, 'admin'), 'le tableau de bord admin n\'est pas touché');
    }
    public function testRoleDefaultIsOwnerOnlyAndUsesTheOwnersCurrentLayout(): void
    {
        $current = ['order' => ['kpi_counters'], 'widths' => []];
        $this->seedLayout(1, 'admin', $current);

        // Un gestionnaire non Owner est refusé.
        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '2'], ['id' => 7, 'is_admin' => 0]));
        $this->assertSame(__('dashboard_layouts_owner_only'), $this->flash());

        // L'Owner l'obtient ; un rôle inconnu est refusé.
        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '2'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_saved'), $this->flash());
        $this->assertSame($current, RoleDashboardLayout::layoutFor(2, 'admin'), 'disposition enregistrée pour le rôle');
        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '99'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_role_unknown'), $this->flash());
    }

    public function testRoleDefaultNeedsACustomizedLayoutAndCanBeCleared(): void
    {
        RoleDashboardLayout::put(2, 'admin', ['order' => ['kpi_counters'], 'widths' => []]);

        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '2'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_customize_first'), $this->flash());
        $this->assertNotNull(RoleDashboardLayout::layoutFor(2, 'admin'), 'rien n\'est effacé par role_default');

        $this->controller->manageManager($this->request(['action' => 'role_clear', 'role_id' => '2'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_saved'), $this->flash());
        $this->assertNull(RoleDashboardLayout::layoutFor(2, 'admin'));
    }

    public function testUnknownActionIsRejected(): void
    {
        $this->controller->manageManager($this->request(['action' => 'drop_everything']));

        $this->assertSame(__('dashboard_layouts_invalid'), $this->flash());
        $this->assertSame([], UserDashboardLayout::forUser(7, 'admin'));
    }}
