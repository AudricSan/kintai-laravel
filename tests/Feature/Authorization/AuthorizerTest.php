<?php

namespace Tests\Feature\Authorization;

use App\Authorization\Authorizer;
use App\Authorization\OwnerGuard;
use App\Authorization\PermissionRegistry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class AuthorizerTest extends TestCase
{
    use CreatesAccess, RefreshDatabase;

    private Authorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->authorizer = app(Authorizer::class);
    }

    public function test_owner_can_do_everything(): void
    {
        $owner = $this->owner();
        $store = Store::factory()->create();

        $this->assertTrue($this->authorizer->isOwner($owner));
        $this->assertTrue($owner->can('shifts.update', $store));
        $this->assertTrue($owner->can('payroll.export'));
        $this->assertNull($this->authorizer->storeIdsFor($owner, 'shifts.update'));
    }

    public function test_store_manager_is_limited_to_their_store(): void
    {
        [$a, $b] = [Store::factory()->create(), Store::factory()->create()];
        $manager = $this->managerOf($a);

        $this->assertTrue($manager->can('shifts.update', $a));
        $this->assertTrue($manager->can('shifts.update', $a->id));
        $this->assertFalse($manager->can('shifts.update', $b));
        $this->assertTrue($manager->can('shifts.update'), 'Sans magasin : « quelque part ».');
        $this->assertFalse($manager->can('stores.create'), 'Permission que le rôle manager n\'a pas.');
        $this->assertFalse($this->authorizer->isOwner($manager));
        $this->assertTrue($this->authorizer->isManager($manager));
        $this->assertSame([$a->id], $this->authorizer->storeIdsFor($manager, 'shifts.update'));
        $this->assertFalse($this->authorizer->hasUnrestricted($manager, 'shifts.update'));
    }

    public function test_all_stores_permission_ignores_the_store(): void
    {
        [$a, $b] = [Store::factory()->create(), Store::factory()->create()];
        $role = $this->customRole(['shifts.view'], allStores: ['shifts.view']);
        $user = User::factory()->create();
        $user->assignRole($role, $a);

        $this->assertTrue($user->can('shifts.view', $b));
        $this->assertTrue($this->authorizer->hasUnrestricted($user, 'shifts.view'));
        $this->assertNull($this->authorizer->storeIdsFor($user, 'shifts.view'));
    }

    public function test_global_assignment_of_a_non_system_role_applies_everywhere(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $user->assignRole($this->role('manager'));

        $this->assertTrue($user->can('shifts.update', $store));
        $this->assertNull($this->authorizer->storeIdsFor($user, 'shifts.update'));
        $this->assertFalse($this->authorizer->isOwner($user));
    }

    public function test_employee_without_role_or_with_employee_role_has_no_management_rights(): void
    {
        $store = Store::factory()->create();
        $plain = User::factory()->create();
        $employee = User::factory()->create();
        $employee->assignRole($this->role('employee'), $store);

        foreach ([$plain, $employee] as $user) {
            $this->assertFalse($user->can('shifts.view', $store));
            $this->assertFalse($user->can('shifts.view'));
            $this->assertFalse($this->authorizer->isManager($user));
            $this->assertSame([], $this->authorizer->storeIdsFor($user, 'shifts.view'));
        }
    }

    public function test_can_resolves_the_store_of_a_model(): void
    {
        [$a, $b] = [Store::factory()->create(), Store::factory()->create()];
        $manager = $this->managerOf($a);
        $shiftA = Shift::factory()->create(['store_id' => $a->id]);
        $shiftB = Shift::factory()->create(['store_id' => $b->id]);

        $this->assertTrue($manager->can('shifts.update', $shiftA));
        $this->assertFalse($manager->can('shifts.update', $shiftB));
    }

    public function test_unknown_abilities_are_left_to_the_gate(): void
    {
        $this->assertTrue($this->owner()->can('something.unknown'), 'Owner : Gate::before.');
        $this->assertFalse(User::factory()->create()->can('something.unknown'));
    }

    public function test_can_middleware_and_blade_style_checks_work(): void
    {
        $store = Store::factory()->create();
        Route::middleware(['web', 'auth', 'can:shifts.view,store'])
            ->get('/test-stores/{store}', fn (Store $store) => 'ok');
        $other = Store::factory()->create();
        $manager = $this->managerOf($store);

        $this->actingAs($manager)->get("/test-stores/{$store->id}")->assertOk();
        $this->actingAs($manager)->get("/test-stores/{$other->id}")->assertForbidden();
        $this->actingAs($this->owner())->get("/test-stores/{$other->id}")->assertOk();
        $this->assertTrue(Gate::forUser($manager)->allows('stores.view', $store));
    }

    public function test_can_assign_role_is_capped_by_the_actors_own_rights(): void
    {
        [$a, $b] = [Store::factory()->create(), Store::factory()->create()];
        $manager = $this->managerOf($a);
        $managerRole = $this->role('manager');
        $payrollOnly = $this->customRole(['payroll.view']);
        $stronger = $this->customRole(['stores.create']);
        $allStores = $this->customRole(['shifts.view'], allStores: ['shifts.view']);

        $this->assertTrue($this->authorizer->canAssignRole($manager, $managerRole, $a));
        $this->assertFalse($this->authorizer->canAssignRole($manager, $managerRole, $b));
        $this->assertFalse($this->authorizer->canAssignRole($manager, $managerRole, null), 'Global : sans restriction requise.');
        $this->assertTrue($this->authorizer->canAssignRole($manager, $payrollOnly, $a));
        $this->assertFalse($this->authorizer->canAssignRole($manager, $stronger, $a));
        $this->assertFalse($this->authorizer->canAssignRole($manager, $allStores, $a));
        $this->assertFalse($this->authorizer->canAssignRole($manager, $this->role('owner'), null));
        $this->assertFalse($this->authorizer->canAssignRole($manager, $this->role('owner'), $a));

        $owner = $this->owner();
        $this->assertTrue($this->authorizer->canAssignRole($owner, $this->role('owner'), null));
        $this->assertFalse($this->authorizer->canAssignRole($owner, $this->role('owner'), $a));
        $this->assertTrue($this->authorizer->canAssignRole($owner, $allStores, null));
    }

    public function test_can_act_on_user(): void
    {
        [$a, $b] = [Store::factory()->create(), Store::factory()->create()];
        $manager = $this->managerOf($a);
        $inA = User::factory()->create();
        $inB = User::factory()->create();
        $a->members()->attach($inA);
        $b->members()->attach($inB);
        $owner = $this->owner();
        $a->members()->attach($owner);

        $this->assertTrue($this->authorizer->canActOnUser($manager, 'employees.update', $manager));
        $this->assertTrue($this->authorizer->canActOnUser($manager, 'employees.update', $inA));
        $this->assertFalse($this->authorizer->canActOnUser($manager, 'employees.update', $inB));
        $this->assertFalse($this->authorizer->canActOnUser($manager, 'employees.update', $owner));
        $this->assertTrue($this->authorizer->canActOnUser($owner, 'employees.update', $inB));
    }

    public function test_non_owner_with_unrestricted_permission_still_cannot_act_on_an_owner(): void
    {
        $globalManager = User::factory()->create();
        $globalManager->assignRole($this->role('manager'));

        $this->assertFalse($this->authorizer->canActOnUser($globalManager, 'employees.update', $this->owner()));
    }

    public function test_owner_ids_and_active_owner_ids(): void
    {
        $active = $this->owner();
        $inactive = $this->owner();
        $inactive->forceFill(['is_active' => false])->save();
        $deleted = $this->owner();
        $deleted->delete();

        $this->assertEqualsCanonicalizing([$active->id, $inactive->id, $deleted->id], $this->authorizer->ownerIds());
        $this->assertSame([$active->id], $this->authorizer->activeOwnerIds());
    }

    public function test_owner_guard_protects_the_last_active_owner_and_self(): void
    {
        $guard = app(OwnerGuard::class);
        $owner = $this->owner();
        $manager = User::factory()->create();

        // Seul Owner actif : personne ne peut le retirer.
        try {
            $guard->assertCanRemoveOwner($manager, $owner);
            $this->fail('Le dernier Owner ne doit pas pouvoir être retiré.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // Cible non Owner : sans effet.
        $guard->assertCanRemoveOwner($owner, $manager);

        // Deux Owners : l'un peut retirer l'autre, jamais soi-même.
        $second = $this->owner();
        $guard->assertCanRemoveOwner($owner, $second);
        $this->expectException(AuthorizationException::class);
        $guard->assertCanRemoveOwner($owner, $owner);
    }

    public function test_owner_guard_refuses_removal_when_the_other_owner_is_inactive(): void
    {
        $guard = app(OwnerGuard::class);
        $owner = $this->owner();
        $other = $this->owner();
        $other->forceFill(['is_active' => false])->save();

        $this->expectException(AuthorizationException::class);
        $guard->assertCanRemoveOwner(User::factory()->create(), $owner);
    }

    public function test_not_self_unless_owner(): void
    {
        $guard = app(OwnerGuard::class);
        $owner = $this->owner();
        $manager = $this->managerOf(Store::factory()->create());

        $guard->assertNotSelfUnlessOwner($owner, $owner);
        $guard->assertNotSelfUnlessOwner($manager, $owner);

        $this->expectException(AuthorizationException::class);
        $guard->assertNotSelfUnlessOwner($manager, $manager);
    }

    public function test_assign_role_is_idempotent(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $role = $this->role('manager');

        $first = $user->assignRole($role, $store);
        $second = $user->assignRole($role, $store);
        $user->assignRole($role);
        $user->assignRole($role);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $user->roles()->count());

        $user->removeRole($role, $store);
        $this->assertSame(1, $user->roles()->count());
    }

    public function test_system_role_cannot_be_assigned_to_a_store(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::factory()->create()->assignRole($this->role('owner'), Store::factory()->create());
    }

    public function test_assigning_a_role_refreshes_the_cached_rights(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $this->assertFalse($user->can('shifts.update', $store));
        $user->assignRole($this->role('manager'), $store);
        $this->assertTrue($user->can('shifts.update', $store));
        $user->removeRole($this->role('manager'), $store);
        $this->assertFalse($user->can('shifts.update', $store));
    }

    public function test_registry_rejects_duplicates_and_invalid_actions(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->assertTrue($registry->has('shifts.update'));
        $this->assertFalse($registry->has('shifts.fly'));
        $this->assertArrayHasKey('shifts', $registry->categories());
        $this->assertContains('payroll.export', $registry->all());

        $fresh = new PermissionRegistry;
        $fresh->register('leave', ['request', 'approve']);
        $this->assertSame(['leave' => ['request', 'approve']], $fresh->categories());
        $this->assertSame(['leave.request', 'leave.approve'], $fresh->all());

        try {
            $fresh->register('leave', ['other']);
            $this->fail('Catégorie en double acceptée.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $fresh->register('other', ['Bad-Action']);
    }

    public function test_bundle_registered_permissions_are_enforced_by_the_gate(): void
    {
        app(PermissionRegistry::class)->register('leave', ['approve']);
        $store = Store::factory()->create();
        $role = $this->customRole(['leave.approve']);
        $user = User::factory()->create();
        $user->assignRole($role, $store);

        $this->assertTrue($user->can('leave.approve', $store));
        $this->assertFalse($user->can('leave.approve', Store::factory()->create()));
    }
}
