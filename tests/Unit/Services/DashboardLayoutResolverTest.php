<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\RoleAssignment;
use App\Models\RoleDashboardLayout;
use App\Models\Role;
use App\Models\UserDashboardPref;
use App\Services\DashboardLayoutResolver;
use PHPUnit\Framework\TestCase;

/** Disposition affichée : celle de l'utilisateur, sinon le défaut de son rôle (système d'abord), sinon le défaut du Core. */
final class DashboardLayoutResolverTest extends TestCase
{
protected function setUp(): void    {        kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();    }
    use \Tests\Support\PrefsExpectations;

    /**
     * @param array<string, mixed>|null    $userLayout
     * @param array<int, array<string, mixed>> $roleDefaults role_id => disposition (admin)
     * @param list<int>                    $userRoles       rôles de l'utilisateur, dans l'ordre d'attribution
     */
    private function resolver(?array $userLayout, array $roleDefaults, array $userRoles, array $systemRoles = []): DashboardLayoutResolver
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(7);
        $this->seedLayout(7, 'admin', $userLayout);
        foreach ($roleDefaults as $roleId => $layout) {
            RoleDashboardLayout::put($roleId, 'admin', $layout);
        }
        kintai_test_assignments(findByUser: fn() => array_map(fn(int $id): array => ['role_id' => $id, 'scope_type' => 'global'], $userRoles));
        kintai_test_roles(findById: fn(int $id): array => ['id' => $id, 'name' => 'Role ' . $id, 'is_system' => in_array($id, $systemRoles, true) ? 1 : 0]);

        return new DashboardLayoutResolver();
    }

    public function testTheUsersOwnLayoutAlwaysWins(): void
    {
        $own = ['order' => ['shifts_today'], 'widths' => []];

        $this->assertSame($own, $this->resolver($own, [2 => ['order' => ['kpi_counters'], 'widths' => []]], [2])->layoutFor(7, 'admin'));
    }

    public function testWithoutAnythingSavedTheRoleDefaultApplies(): void
    {
        $default = ['order' => ['kpi_counters'], 'widths' => ['kpi_counters' => 4]];

        $this->assertSame($default, $this->resolver(null, [2 => $default], [2])->layoutFor(7, 'admin'));
    }

    public function testWithoutAnyDefaultTheCoreDefaultApplies(): void
    {
        $this->assertNull($this->resolver(null, [], [2])->layoutFor(7, 'admin'));
        $this->assertNull($this->resolver(null, [2 => ['order' => ['kpi_counters'], 'widths' => []]], [2])->layoutFor(7, 'employee'), 'un défaut est propre à un tableau de bord');
    }

    public function testSeveralRolesTheSystemRoleComesFirstThenTheLowestId(): void
    {
        $owner   = ['order' => ['owner_first'], 'widths' => []];
        $low     = ['order' => ['low_id'], 'widths' => []];
        $high    = ['order' => ['high_id'], 'widths' => []];
        $defaults = [1 => $owner, 3 => $low, 5 => $high];

        $this->assertSame($owner, $this->resolver(null, $defaults, [5, 3, 1], [1])->roleDefault(7, 'admin'));
        $this->assertSame($low, $this->resolver(null, [3 => $low, 5 => $high], [5, 3])->roleDefault(7, 'admin'));
        $this->assertSame($high, $this->resolver(null, [5 => $high], [3, 5])->roleDefault(7, 'admin'), 'un rôle sans défaut est ignoré');
    }

    public function testRoleOptionsListEveryRoleAndWhichOnesHaveADefault(): void
    {
        $resolver = $this->resolver(null, [2 => ['order' => ['kpi_counters'], 'widths' => []]], []);
        \Illuminate\Support\Facades\DB::table('roles')->whereNotIn('id', [1, 2])->delete();   // seuls Owner et Manager
        \App\Database\RequestMemo::flush();
        $options = $resolver->roleOptions('admin');

        $this->assertSame([1 => ['name' => 'Owner', 'has_default' => false], 2 => ['name' => 'Manager', 'has_default' => true]], $options);
    }
}
