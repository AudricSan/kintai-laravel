<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserDashboardLayoutRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Services\DashboardLayoutResolver;
use PHPUnit\Framework\TestCase;

/** Disposition affichée : celle de l'utilisateur, sinon le défaut de son rôle (système d'abord), sinon le défaut du Core. */
final class DashboardLayoutResolverTest extends TestCase
{
    /**
     * @param array<string, mixed>|null    $userLayout
     * @param array<int, array<string, mixed>> $roleDefaults role_id => disposition (admin)
     * @param list<int>                    $userRoles       rôles de l'utilisateur, dans l'ordre d'attribution
     */
    private function resolver(?array $userLayout, array $roleDefaults, array $userRoles, array $systemRoles = []): DashboardLayoutResolver
    {
        $prefs = $this->createStub(UserDashboardPrefsRepositoryInterface::class);
        $prefs->method('getLayout')->willReturn($userLayout);

        $roleLayouts = $this->createStub(RoleDashboardLayoutRepositoryInterface::class);
        $roleLayouts->method('get')->willReturnCallback(fn(int $roleId, string $type): ?array => $type === 'admin' ? ($roleDefaults[$roleId] ?? null) : null);

        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn(array_map(fn(int $id): array => ['role_id' => $id, 'scope_type' => 'global'], $userRoles));

        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturnCallback(fn(int $id): array => ['id' => $id, 'name' => 'Role ' . $id, 'is_system' => in_array($id, $systemRoles, true) ? 1 : 0]);
        $roles->method('findAll')->willReturn([['id' => 1, 'name' => 'Owner'], ['id' => 2, 'name' => 'Manager']]);

        return new DashboardLayoutResolver($prefs, $roleLayouts, $assignments, $roles, $this->createStub(UserDashboardLayoutRepositoryInterface::class));
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
        $options = $this->resolver(null, [2 => ['order' => ['kpi_counters'], 'widths' => []]], [])->roleOptions('admin');

        $this->assertSame([1 => ['name' => 'Owner', 'has_default' => false], 2 => ['name' => 'Manager', 'has_default' => true]], $options);
    }
}
