<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Repositories\DatabaseRoleDashboardLayoutRepository;
use kintai\Core\Repositories\DatabaseUserDashboardLayoutRepository;
use PHPUnit\Framework\TestCase;

/** Mises en page nommées d'un utilisateur et défaut par rôle, contre un vrai schéma (celui de la migration). */
final class DashboardLayoutRepositoriesTest extends TestCase
{
    private DatabaseUserDashboardLayoutRepository $named;
    private DatabaseRoleDashboardLayoutRepository $roles;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Les migrations lisent $this->capsule : on les charge depuis un objet qui le porte, comme MigrationRunner.
        $loader = new class($capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 3) . '/database/migrations/php/2026_10_23_000000_create_dashboard_layout_tables.php')->up();

        $this->named = new DatabaseUserDashboardLayoutRepository();
        $this->roles = new DatabaseRoleDashboardLayoutRepository();
    }

    private function layout(string ...$order): array
    {
        return ['order' => $order, 'widths' => []];
    }

    public function testCreatingALayoutMakesItTheOnlyActiveOne(): void
    {
        $first  = $this->named->createActive(7, 'admin', 'Matin', $this->layout('kpi_counters'));
        $second = $this->named->createActive(7, 'admin', 'Gestion', $this->layout('shifts_today', 'kpi_counters'));

        $list = $this->named->forUser(7, 'admin');
        $this->assertSame(['Matin', 'Gestion'], array_column($list, 'name'), 'dans l\'ordre de création');
        $this->assertSame([false, true], array_column($list, 'is_active'));
        $this->assertSame(['shifts_today', 'kpi_counters'], $list[1]['layout']['order']);
        $this->assertNotSame($first, $second);
    }

    public function testLayoutsAreScopedByUserAndDashboardType(): void
    {
        $this->named->createActive(7, 'admin', 'Matin', $this->layout('kpi_counters'));
        $this->named->createActive(8, 'admin', 'Matin', $this->layout('kpi_counters'));
        $this->named->createActive(7, 'employee', 'Matin', $this->layout('upcoming'));

        $this->assertCount(1, $this->named->forUser(7, 'admin'));
        $this->assertCount(1, $this->named->forUser(7, 'employee'));
        $this->assertSame([], $this->named->forUser(9, 'admin'));
    }

    public function testActivateSwitchesTheActiveLayoutWithinTheSameDashboardOnly(): void
    {
        $a = $this->named->createActive(7, 'admin', 'A', $this->layout('kpi_counters'));
        $b = $this->named->createActive(7, 'admin', 'B', $this->layout('shifts_today'));
        $other = $this->named->createActive(7, 'employee', 'E', $this->layout('upcoming'));

        $this->named->activate($a);

        $this->assertSame([true, false], array_column($this->named->forUser(7, 'admin'), 'is_active'));
        $this->assertTrue($this->named->forUser(7, 'employee')[0]['is_active'], 'l\'autre tableau de bord n\'est pas touché');
        $this->assertNotNull($this->named->find($b));
        $this->assertNotNull($this->named->find($other));
    }

    public function testUpdateActiveOnlyTouchesTheActiveLayout(): void
    {
        $a = $this->named->createActive(7, 'admin', 'A', $this->layout('kpi_counters'));
        $b = $this->named->createActive(7, 'admin', 'B', $this->layout('shifts_today'));

        $this->named->updateActive(7, 'admin', $this->layout('dashboard_alerts', 'shifts_today'));

        $this->assertSame(['kpi_counters'], $this->named->find($a)['layout']['order']);
        $this->assertSame(['dashboard_alerts', 'shifts_today'], $this->named->find($b)['layout']['order']);
    }

    public function testDeactivateAllLeavesNoActiveLayout(): void
    {
        $this->named->createActive(7, 'admin', 'A', $this->layout('kpi_counters'));
        $this->named->deactivateAll(7, 'admin');
        $this->named->updateActive(7, 'admin', $this->layout('shifts_today'));

        $list = $this->named->forUser(7, 'admin');
        $this->assertSame([false], array_column($list, 'is_active'));
        $this->assertSame(['kpi_counters'], $list[0]['layout']['order'], 'sans mise en page active, rien n\'est modifié');
    }

    public function testRenameAndDelete(): void
    {
        $id = $this->named->createActive(7, 'admin', 'A', $this->layout('kpi_counters'));

        $this->named->rename($id, 'Nouveau nom');
        $this->assertSame('Nouveau nom', $this->named->find($id)['name']);
        $this->assertSame(7, $this->named->find($id)['user_id']);
        $this->assertSame('admin', $this->named->find($id)['dashboard_type']);

        $this->named->delete($id);
        $this->assertNull($this->named->find($id));
    }

    public function testRoleDefaultsAreStoredPerRoleAndDashboard(): void
    {
        $this->assertNull($this->roles->get(3, 'admin'));

        $this->roles->save(3, 'admin', $this->layout('kpi_counters'));
        $this->roles->save(3, 'admin', $this->layout('shifts_today'));
        $this->roles->save(3, 'employee', $this->layout('upcoming'));
        $this->roles->save(4, 'admin', $this->layout('dashboard_alerts'));

        $this->assertSame(['shifts_today'], $this->roles->get(3, 'admin')['order'], 'enregistrer remplace');
        $this->assertSame(['upcoming'], $this->roles->get(3, 'employee')['order']);
        $this->assertSame(['dashboard_alerts'], $this->roles->get(4, 'admin')['order']);

        $this->roles->delete(3, 'admin');
        $this->assertNull($this->roles->get(3, 'admin'));
        $this->assertNotNull($this->roles->get(3, 'employee'));

        $this->roles->deleteForRole(3);
        $this->assertNull($this->roles->get(3, 'employee'));
        $this->assertNotNull($this->roles->get(4, 'admin'));
    }
}
