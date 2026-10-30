<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\RoleDashboardLayout;
use App\Models\UserDashboardLayout;
use PHPUnit\Framework\TestCase;

/** Mises en page nommées d'un utilisateur et défaut par rôle, contre un vrai schéma (celui de la migration). */
final class DashboardLayoutRepositoriesTest extends TestCase
{

    protected function setUp(): void
    {
        kintai_test_database();
        (require dirname(__DIR__, 3) . '/database/migrations/2026_11_01_000003_create_application_tables.php')->up();

    }

    private function layout(string ...$order): array
    {
        return ['order' => $order, 'widths' => []];
    }

    public function testCreatingALayoutMakesItTheOnlyActiveOne(): void
    {
        $first  = UserDashboardLayout::createActive(7, 'admin', 'Matin', $this->layout('kpi_counters'));
        $second = UserDashboardLayout::createActive(7, 'admin', 'Gestion', $this->layout('shifts_today', 'kpi_counters'));

        $list = UserDashboardLayout::forUser(7, 'admin');
        $this->assertSame(['Matin', 'Gestion'], array_column($list, 'name'), 'dans l\'ordre de création');
        $this->assertSame([false, true], array_column($list, 'is_active'));
        $this->assertSame(['shifts_today', 'kpi_counters'], $list[1]['layout']['order']);
        $this->assertNotSame($first, $second);
    }

    public function testLayoutsAreScopedByUserAndDashboardType(): void
    {
        UserDashboardLayout::createActive(7, 'admin', 'Matin', $this->layout('kpi_counters'));
        UserDashboardLayout::createActive(8, 'admin', 'Matin', $this->layout('kpi_counters'));
        UserDashboardLayout::createActive(7, 'employee', 'Matin', $this->layout('upcoming'));

        $this->assertCount(1, UserDashboardLayout::forUser(7, 'admin'));
        $this->assertCount(1, UserDashboardLayout::forUser(7, 'employee'));
        $this->assertSame([], UserDashboardLayout::forUser(9, 'admin'));
    }

    public function testActivateSwitchesTheActiveLayoutWithinTheSameDashboardOnly(): void
    {
        $a = UserDashboardLayout::createActive(7, 'admin', 'A', $this->layout('kpi_counters'));
        $b = UserDashboardLayout::createActive(7, 'admin', 'B', $this->layout('shifts_today'));
        $other = UserDashboardLayout::createActive(7, 'employee', 'E', $this->layout('upcoming'));

        UserDashboardLayout::activate($a);

        $this->assertSame([true, false], array_column(UserDashboardLayout::forUser(7, 'admin'), 'is_active'));
        $this->assertTrue(UserDashboardLayout::forUser(7, 'employee')[0]['is_active'], 'l\'autre tableau de bord n\'est pas touché');
        $this->assertNotNull(UserDashboardLayout::describe($b));
        $this->assertNotNull(UserDashboardLayout::describe($other));
    }

    public function testUpdateActiveOnlyTouchesTheActiveLayout(): void
    {
        $a = UserDashboardLayout::createActive(7, 'admin', 'A', $this->layout('kpi_counters'));
        $b = UserDashboardLayout::createActive(7, 'admin', 'B', $this->layout('shifts_today'));

        UserDashboardLayout::updateActive(7, 'admin', $this->layout('dashboard_alerts', 'shifts_today'));

        $this->assertSame(['kpi_counters'], UserDashboardLayout::describe($a)['layout']['order']);
        $this->assertSame(['dashboard_alerts', 'shifts_today'], UserDashboardLayout::describe($b)['layout']['order']);
    }

    public function testDeactivateAllLeavesNoActiveLayout(): void
    {
        UserDashboardLayout::createActive(7, 'admin', 'A', $this->layout('kpi_counters'));
        UserDashboardLayout::deactivateAll(7, 'admin');
        UserDashboardLayout::updateActive(7, 'admin', $this->layout('shifts_today'));

        $list = UserDashboardLayout::forUser(7, 'admin');
        $this->assertSame([false], array_column($list, 'is_active'));
        $this->assertSame(['kpi_counters'], $list[0]['layout']['order'], 'sans mise en page active, rien n\'est modifié');
    }

    public function testRenameAndDelete(): void
    {
        $id = UserDashboardLayout::createActive(7, 'admin', 'A', $this->layout('kpi_counters'));

        UserDashboardLayout::where('id', $id)->update(['name' => 'Nouveau nom']);
        $this->assertSame('Nouveau nom', UserDashboardLayout::describe($id)['name']);
        $this->assertSame(7, UserDashboardLayout::describe($id)['user_id']);
        $this->assertSame('admin', UserDashboardLayout::describe($id)['dashboard_type']);

        UserDashboardLayout::destroy($id);
        $this->assertNull(UserDashboardLayout::describe($id));
    }

    public function testRoleDefaultsAreStoredPerRoleAndDashboard(): void
    {
        $this->assertNull(RoleDashboardLayout::layoutFor(3, 'admin'));

        RoleDashboardLayout::put(3, 'admin', $this->layout('kpi_counters'));
        RoleDashboardLayout::put(3, 'admin', $this->layout('shifts_today'));
        RoleDashboardLayout::put(3, 'employee', $this->layout('upcoming'));
        RoleDashboardLayout::put(4, 'admin', $this->layout('dashboard_alerts'));

        $this->assertSame(['shifts_today'], RoleDashboardLayout::layoutFor(3, 'admin')['order'], 'enregistrer remplace');
        $this->assertSame(['upcoming'], RoleDashboardLayout::layoutFor(3, 'employee')['order']);
        $this->assertSame(['dashboard_alerts'], RoleDashboardLayout::layoutFor(4, 'admin')['order']);

        RoleDashboardLayout::where('role_id', 3)->where('dashboard_type', 'admin')->delete();
        $this->assertNull(RoleDashboardLayout::layoutFor(3, 'admin'));
        $this->assertNotNull(RoleDashboardLayout::layoutFor(3, 'employee'));

        RoleDashboardLayout::where('role_id', 3)->delete();
        $this->assertNull(RoleDashboardLayout::layoutFor(3, 'employee'));
        $this->assertNotNull(RoleDashboardLayout::layoutFor(4, 'admin'));
    }
}
