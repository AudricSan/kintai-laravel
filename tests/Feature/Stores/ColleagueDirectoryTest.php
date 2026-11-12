<?php

namespace Tests\Feature\Stores;

use App\Models\EmployeeProfile;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Contracts\Stores\Colleague;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Le service du contrat qui donne les collègues d'un utilisateur : membres actifs des magasins en commun, coordonnées
 * seulement si partagées ou si le demandeur gère le magasin, jamais un compte inactif ou anonymisé.
 */
class ColleagueDirectoryTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private ColleagueDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
        $this->directory = app(ColleagueDirectory::class);
    }

    /**
     * @param  list<Colleague>  $colleagues
     * @return list<int>
     */
    private function ids(array $colleagues): array
    {
        return array_map(fn (Colleague $colleague): int => $colleague->id, $colleagues);
    }

    public function test_stores_are_the_active_memberships_of_the_viewer(): void
    {
        $viewer = $this->employeeOf($this->a);
        $this->b->members()->attach($viewer, ['is_active' => false]);

        $this->assertSame([$this->a->id => 'Alpha'], $this->directory->stores($viewer));
    }

    public function test_the_owner_sees_every_store_and_deleted_stores_are_ignored(): void
    {
        $owner = $this->owner();
        Store::factory()->create(['name' => 'Gone'])->delete();

        $this->assertSame([$this->a->id => 'Alpha', $this->b->id => 'Beta'], $this->directory->stores($owner));
    }

    public function test_stores_can_be_limited_to_those_with_a_feature_enabled(): void
    {
        $viewer = $this->employeeOf($this->a);
        $this->b->members()->attach($viewer);
        StoreFeature::create(['store_id' => $this->b->id, 'feature' => 'demo', 'enabled' => false]);
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'demo', 'enabled' => true]);

        $this->assertSame([$this->a->id => 'Alpha'], $this->directory->stores($viewer, 'demo'));
        $this->assertCount(2, $this->directory->stores($viewer, 'unknown'), 'Without a row the feature is on.');
    }

    public function test_colleagues_are_active_members_of_shared_stores_only(): void
    {
        $viewer = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a);
        $inactiveAccount = $this->employeeOf($this->a, ['is_active' => false]);
        $anonymized = User::factory()->anonymized()->create();
        $this->a->members()->attach($anonymized);
        $inactiveMember = $this->employeeOf($this->a);
        $this->a->members()->updateExistingPivot($inactiveMember->id, ['is_active' => false]);
        $elsewhere = $this->employeeOf($this->b);
        $noStore = User::factory()->create();

        $found = $this->ids($this->directory->colleagues($viewer, [$this->a->id, $this->b->id]));

        $this->assertSame([$colleague->id], $found);
        $this->assertNotContains($viewer->id, $found);
        $this->assertNotContains($inactiveAccount->id, $found);
        $this->assertNotContains($anonymized->id, $found);
        $this->assertNotContains($inactiveMember->id, $found);
        $this->assertNotContains($elsewhere->id, $found);
        $this->assertNotContains($noStore->id, $found);
    }

    public function test_asking_for_a_store_the_viewer_cannot_see_returns_nothing(): void
    {
        $viewer = $this->employeeOf($this->a);
        $this->employeeOf($this->b);

        $this->assertSame([], $this->directory->colleagues($viewer, [$this->b->id]));
        $this->assertNull($this->directory->colleague($viewer, $this->employeeOf($this->b)->id, [$this->b->id]));
    }

    public function test_the_owner_sees_colleagues_of_every_store_but_an_owner_outside_the_stores_is_never_listed(): void
    {
        $owner = $this->owner();
        $inA = $this->employeeOf($this->a);
        $inB = $this->employeeOf($this->b);
        $otherOwner = $this->owner();

        $this->assertEqualsCanonicalizing([$inA->id, $inB->id], $this->ids($this->directory->colleagues($owner, [$this->a->id, $this->b->id])));

        $this->assertNotContains($owner->id, $this->ids($this->directory->colleagues($inA, [$this->a->id])));
        $this->assertNotContains($otherOwner->id, $this->ids($this->directory->colleagues($inA, [$this->a->id])));
    }

    public function test_contact_details_are_those_the_colleague_chose_to_share(): void
    {
        $viewer = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a, ['email' => 'jean@example.test', 'share_email' => true, 'share_phone' => false, 'share_mobile_phone' => true]);
        EmployeeProfile::factory()->create(['user_id' => $colleague->id, 'phone' => '01 11', 'mobile_phone' => '06 66', 'address' => 'Secret street']);

        $found = $this->directory->colleague($viewer, $colleague->id, [$this->a->id]);

        $this->assertNotNull($found);
        $this->assertSame('jean@example.test', $found->email);
        $this->assertNull($found->phone);
        $this->assertSame('06 66', $found->mobilePhone);
        $this->assertFalse($found->isSelf);
        $this->assertSame([$this->a->id => 'Alpha'], $found->stores);
    }

    public function test_nothing_is_shared_by_default(): void
    {
        $viewer = $this->employeeOf($this->a);
        $colleague = $this->employeeOf($this->a);
        EmployeeProfile::factory()->create(['user_id' => $colleague->id]);

        $found = $this->directory->colleague($viewer, $colleague->id, [$this->a->id]);

        $this->assertNotNull($found);
        $this->assertFalse($found->hasContact());
    }

    public function test_a_manager_of_a_shared_store_sees_every_contact_detail_of_its_members(): void
    {
        $manager = $this->managerOf($this->a);
        $this->a->members()->attach($manager);
        $colleague = $this->employeeOf($this->a, ['email' => 'jean@example.test']);
        EmployeeProfile::factory()->create(['user_id' => $colleague->id, 'phone' => '01 11', 'mobile_phone' => '06 66']);
        $otherStore = $this->employeeOf($this->b, ['email' => 'other@example.test']);
        $this->b->members()->attach($manager);

        $found = $this->directory->colleague($manager, $colleague->id, [$this->a->id]);

        $this->assertNotNull($found);
        $this->assertSame(['jean@example.test', '01 11', '06 66'], [$found->email, $found->phone, $found->mobilePhone]);
        // Gérant de A seulement : membre de B, il n'y voit que ce qui est partagé.
        $this->assertNull($this->directory->colleague($manager, $otherStore->id, [$this->b->id])?->email);
    }

    public function test_the_owner_sees_all_contact_details(): void
    {
        $owner = $this->owner();
        $colleague = $this->employeeOf($this->a, ['email' => 'jean@example.test']);

        $this->assertSame('jean@example.test', $this->directory->colleague($owner, $colleague->id, [$this->a->id])?->email);
    }

    public function test_a_viewer_always_sees_themselves_with_their_own_contact_details(): void
    {
        $viewer = $this->employeeOf($this->a, ['email' => 'me@example.test']);

        $found = $this->directory->colleague($viewer, $viewer->id, []);

        $this->assertNotNull($found);
        $this->assertTrue($found->isSelf);
        $this->assertSame('me@example.test', $found->email);
        $this->assertSame([], $found->stores, 'No store requested, no store shown.');
        $this->assertSame([], $this->directory->colleagues($viewer, [$this->a->id]), 'Never listed among their own colleagues.');
    }

    public function test_colleagues_are_sorted_by_name(): void
    {
        $viewer = $this->employeeOf($this->a);
        $zed = $this->employeeOf($this->a, ['name' => 'Zed']);
        $anna = $this->employeeOf($this->a, ['name' => 'anna']);

        $this->assertSame([$anna->id, $zed->id], $this->ids($this->directory->colleagues($viewer, [$this->a->id])));
    }
}
