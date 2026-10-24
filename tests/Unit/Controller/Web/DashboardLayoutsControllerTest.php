<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\DashboardLayoutResolver;
use kintai\Core\SessionFlash;
use kintai\UI\Controller\Web\DashboardLayoutsController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Mises en page nommées : chacun ne touche qu'aux siennes, 5 au plus, noms uniques ; défaut par rôle réservé aux Owners. */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DashboardLayoutsControllerTest extends TestCase
{
    private UserDashboardLayoutRepositoryInterface&MockObject $layouts;
    private UserDashboardPrefsRepositoryInterface&MockObject $prefs;
    private RoleDashboardLayoutRepositoryInterface&MockObject $roleLayouts;
    private RoleRepositoryInterface $roles;
    private DashboardLayoutsController $controller;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $this->layouts     = $this->createMock(UserDashboardLayoutRepositoryInterface::class);
        $this->prefs       = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->roleLayouts = $this->createMock(RoleDashboardLayoutRepositoryInterface::class);
        $this->roles       = $this->createStub(RoleRepositoryInterface::class);
        $this->roles->method('findById')->willReturnCallback(fn(int $id): ?array => $id === 2 ? ['id' => 2, 'name' => 'Manager'] : null);

        $resolver = new DashboardLayoutResolver(
            $this->prefs,
            $this->roleLayouts,
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->roles,
            $this->layouts,
        );
        $this->controller = new DashboardLayoutsController($this->layouts, $this->prefs, $this->roleLayouts, $this->roles, $resolver, new AuditLogger());
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
    }

    private function request(array $post, array $user = ['id' => 7]): Request
    {
        $_POST = $post;
        $req = new Request();
        $req->setAttribute('auth_user', $user);
        return $req;
    }

    private function flash(): string
    {
        return implode('|', array_column(SessionFlash::pull(), 'text'));
    }

    private function stored(int $id, int $userId = 7, string $type = 'admin', bool $active = false): array
    {
        return ['id' => $id, 'name' => 'Matin', 'layout' => ['order' => ['shifts_today'], 'widths' => []], 'is_active' => $active, 'position' => 1, 'user_id' => $userId, 'dashboard_type' => $type];
    }

    public function testCreateSnapshotsTheCurrentLayoutAndMakesItActive(): void
    {
        $current = ['order' => ['kpi_counters'], 'widths' => ['kpi_counters' => 4]];
        $this->prefs->method('getLayout')->with(7, 'admin')->willReturn($current);
        $this->layouts->method('forUser')->willReturn([]);
        $this->layouts->expects($this->once())->method('createActive')->with(7, 'admin', 'Matin', $current)->willReturn(1);
        $this->prefs->expects($this->once())->method('saveLayout')->with(7, 'admin', $current);

        $response = $this->controller->manageManager($this->request(['action' => 'create', 'name' => '  Matin ']));

        $this->assertSame(302, $response->status());
        $this->assertSame(__('dashboard_layouts_saved'), $this->flash());
    }

    public function testCreateIsRefusedBeyondFiveLayoutsOrWithADuplicateOrEmptyName(): void
    {
        $five = array_map(fn(int $i): array => ['id' => $i, 'name' => 'L' . $i, 'layout' => [], 'is_active' => false, 'position' => $i], range(1, 5));
        $this->layouts->method('forUser')->willReturnOnConsecutiveCalls($five, [['id' => 1, 'name' => 'Matin', 'layout' => [], 'is_active' => false, 'position' => 1]]);
        $this->layouts->expects($this->never())->method('createActive');

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => 'Sixième']));
        $this->assertSame(__('dashboard_layouts_limit'), $this->flash());

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => 'matin']));
        $this->assertSame(__('dashboard_layouts_name_taken'), $this->flash(), 'noms uniques sans tenir compte de la casse');

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => '   ']));
        $this->assertSame(__('dashboard_layouts_name_invalid'), $this->flash());

        $this->controller->manageManager($this->request(['action' => 'create', 'name' => str_repeat('x', 61)]));
        $this->assertSame(__('dashboard_layouts_name_invalid'), $this->flash());
    }

    public function testActivateCopiesTheLayoutIntoTheCurrentOne(): void
    {
        $this->layouts->method('find')->with(4)->willReturn($this->stored(4));
        $this->prefs->expects($this->once())->method('saveLayout')->with(7, 'admin', ['order' => ['shifts_today'], 'widths' => []]);
        $this->layouts->expects($this->once())->method('activate')->with(4);

        $this->controller->manageManager($this->request(['action' => 'activate', 'id' => '4']));
    }

    public function testNobodyTouchesSomeoneElsesLayoutOrAnotherDashboardsLayout(): void
    {
        $this->layouts->method('find')->willReturnMap([[4, $this->stored(4, userId: 99)], [5, $this->stored(5, type: 'employee')]]);
        $this->layouts->expects($this->never())->method('activate');
        $this->layouts->expects($this->never())->method('delete');
        $this->layouts->expects($this->never())->method('rename');
        $this->prefs->expects($this->never())->method('saveLayout');
        $this->layouts->method('forUser')->willReturn([]);

        foreach (['activate', 'delete', 'rename'] as $action) {
            foreach ([4, 5, 404] as $id) {
                $this->controller->manageManager($this->request(['action' => $action, 'id' => (string) $id, 'name' => 'Autre']));
                $this->assertSame(__('dashboard_layouts_not_found'), $this->flash(), "$action #$id");
            }
        }
    }

    public function testRenameAndDeleteOwnLayout(): void
    {
        $this->layouts->method('find')->with(4)->willReturn($this->stored(4));
        $this->layouts->method('forUser')->willReturn([['id' => 4, 'name' => 'Matin', 'layout' => [], 'is_active' => true, 'position' => 1]]);
        $this->layouts->expects($this->once())->method('rename')->with(4, 'Soir');
        $this->layouts->expects($this->once())->method('delete')->with(4);

        $this->controller->manageManager($this->request(['action' => 'rename', 'id' => '4', 'name' => 'Soir']));
        $this->controller->manageManager($this->request(['action' => 'delete', 'id' => '4']));
    }

    public function testEmployeeDashboardUsesItsOwnType(): void
    {
        $this->layouts->method('find')->with(5)->willReturn($this->stored(5, type: 'employee'));
        $this->prefs->expects($this->once())->method('saveLayout')->with(7, 'employee', $this->anything());

        $this->controller->manageEmployee($this->request(['action' => 'activate', 'id' => '5']));
    }

    public function testRoleDefaultIsOwnerOnlyAndUsesTheOwnersCurrentLayout(): void
    {
        $current = ['order' => ['kpi_counters'], 'widths' => []];
        $this->prefs->method('getLayout')->with(1, 'admin')->willReturn($current);
        $this->roleLayouts->expects($this->once())->method('save')->with(2, 'admin', $current);

        // Un gestionnaire non Owner est refusé.
        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '2'], ['id' => 7, 'is_admin' => 0]));
        $this->assertSame(__('dashboard_layouts_owner_only'), $this->flash());

        // L'Owner l'obtient ; un rôle inconnu est refusé.
        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '2'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_saved'), $this->flash());
        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '99'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_role_unknown'), $this->flash());
    }

    public function testRoleDefaultNeedsACustomizedLayoutAndCanBeCleared(): void
    {
        $this->prefs->method('getLayout')->willReturn(null);
        $this->roleLayouts->expects($this->never())->method('save');
        $this->roleLayouts->expects($this->once())->method('delete')->with(2, 'admin');

        $this->controller->manageManager($this->request(['action' => 'role_default', 'role_id' => '2'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_customize_first'), $this->flash());

        $this->controller->manageManager($this->request(['action' => 'role_clear', 'role_id' => '2'], ['id' => 1, 'is_admin' => 1]));
        $this->assertSame(__('dashboard_layouts_saved'), $this->flash());
    }

    public function testUnknownActionIsRejected(): void
    {
        $this->layouts->expects($this->never())->method('createActive');

        $this->controller->manageManager($this->request(['action' => 'drop_everything']));

        $this->assertSame(__('dashboard_layouts_invalid'), $this->flash());
    }
}
