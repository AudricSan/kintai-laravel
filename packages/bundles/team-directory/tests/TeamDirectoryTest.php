<?php

namespace Kintai\Bundles\TeamDirectory\Tests;

use App\Actions\AnonymizeUser;
use App\Bundles\BundleManager;
use App\Models\ActivityEntry;
use App\Models\EmployeeProfile;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\TeamDirectory\Models\TeamProfile;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Profile\ProfileSectionRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Annuaire des collègues : qui est listé, ce que l'on voit de chacun, qui peut modifier quoi, bundle désactivé.
 */
class TeamDirectoryTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
        $this->viewer = $this->employeeOf($this->a, ['name' => 'Viewer Moi']);
    }

    private function colleague(Store $store, string $name, array $attributes = []): User
    {
        return $this->employeeOf($store, ['name' => $name, ...$attributes]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('team-directory');

        $this->assertTrue(app(NavigationRegistry::class)->has('team-directory'));
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('team-directory'));
        $this->assertTrue(app(ActivityActionRegistry::class)->has('team-directory.profile_updated'));
        $this->assertContains('team-directory', array_map(fn ($section) => $section->key, app(ProfileSectionRegistry::class)->sections($this->viewer)));

        $this->actingAs($this->viewer)->get(route('dashboard'))->assertOk()->assertSee(route('team-directory.index'), false);
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(NavigationRegistry::class)->has('team-directory'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('team-directory'));
        $this->assertSame([], array_filter(
            app(ProfileSectionRegistry::class)->sections($this->viewer),
            fn ($section) => $section->key === 'team-directory',
        ));

        $this->actingAs($this->viewer);
        $this->get('/team-directory')->assertNotFound();
        $this->get('/team-directory/profile')->assertNotFound();
        $this->get('/team-directory/'.$this->viewer->id)->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('/team-directory', false);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableBundle('team-directory');

        $this->get('/team-directory')->assertRedirect(route('login'));
    }

    // -- Liste ---------------------------------------------------------------------------------------------------

    public function test_the_list_shows_colleagues_of_shared_stores_only(): void
    {
        $this->enableBundle('team-directory');
        $this->colleague($this->a, 'Colleague Visible');
        $this->colleague($this->b, 'Stranger Elsewhere');
        $this->colleague($this->a, 'Inactive Account', ['is_active' => false]);
        $anonymized = User::factory()->anonymized()->create(['name' => 'Gone Anonymous']);
        $this->a->members()->attach($anonymized);
        $this->owner()->update(['name' => 'Owner Outside']);

        Livewire::actingAs($this->viewer)->test('team-directory.index')
            ->assertSee('Colleague Visible')
            ->assertSee('Alpha')
            ->assertDontSee('Viewer Moi')
            ->assertDontSee('Stranger Elsewhere')
            ->assertDontSee('Inactive Account')
            ->assertDontSee('Gone Anonymous')
            ->assertDontSee('Owner Outside');
    }

    public function test_the_list_never_shows_contact_details(): void
    {
        $this->enableBundle('team-directory');
        $this->colleague($this->a, 'Colleague Visible', ['email' => 'visible@example.test', 'share_email' => true]);

        Livewire::actingAs($this->viewer)->test('team-directory.index')
            ->assertSee('Colleague Visible')
            ->assertDontSee('visible@example.test');
    }

    public function test_someone_who_left_the_directory_is_not_listed(): void
    {
        $this->enableBundle('team-directory');
        $hidden = $this->colleague($this->a, 'Colleague Hidden');
        TeamProfile::create(['user_id' => $hidden->id, 'show_in_directory' => false]);
        $this->colleague($this->a, 'Colleague Shown');

        Livewire::actingAs($this->viewer)->test('team-directory.index')
            ->assertSee('Colleague Shown')
            ->assertDontSee('Colleague Hidden');
    }

    public function test_the_list_can_be_searched_and_filtered_by_store(): void
    {
        $this->enableBundle('team-directory');
        $this->b->members()->attach($this->viewer);
        $this->colleague($this->a, 'Anna Alpha');
        $this->colleague($this->b, 'Bert Beta');

        Livewire::actingAs($this->viewer)->test('team-directory.index')
            ->assertSee('Anna Alpha')->assertSee('Bert Beta')
            ->set('search', 'anna')->assertSee('Anna Alpha')->assertDontSee('Bert Beta')
            ->set('search', '')->set('store', (string) $this->b->id)->assertSee('Bert Beta')->assertDontSee('Anna Alpha')
            ->set('store', '999')->assertSee('Anna Alpha')->assertSee('Bert Beta');
    }

    public function test_the_owner_sees_the_whole_organisation(): void
    {
        $this->enableBundle('team-directory');
        $this->colleague($this->a, 'Anna Alpha');
        $this->colleague($this->b, 'Bert Beta');

        Livewire::actingAs($this->owner())->test('team-directory.index')->assertSee('Anna Alpha')->assertSee('Bert Beta');
    }

    // -- Fonctionnalité de magasin -------------------------------------------------------------------------------

    public function test_a_store_without_the_feature_is_left_out(): void
    {
        $this->enableBundle('team-directory');
        $this->b->members()->attach($this->viewer);
        $this->colleague($this->a, 'Anna Alpha');
        $this->colleague($this->b, 'Bert Beta');
        StoreFeature::create(['store_id' => $this->b->id, 'feature' => 'team-directory', 'enabled' => false]);

        Livewire::actingAs($this->viewer)->test('team-directory.index')->assertSee('Anna Alpha')->assertDontSee('Bert Beta');
    }

    public function test_without_the_feature_in_any_store_the_screen_and_its_menu_entry_are_gone(): void
    {
        $this->enableBundle('team-directory');
        $anna = $this->colleague($this->a, 'Anna Alpha');
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'team-directory', 'enabled' => false]);

        $this->actingAs($this->viewer);
        $this->get('/team-directory')->assertNotFound();
        $this->get('/team-directory/profile')->assertNotFound();
        $this->get('/team-directory/'.$anna->id)->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('team-directory.index'), false);
    }

    public function test_a_user_without_any_store_has_no_directory(): void
    {
        $this->enableBundle('team-directory');

        $this->actingAs(User::factory()->create())->get('/team-directory')->assertNotFound();
    }

    // -- Fiche ---------------------------------------------------------------------------------------------------

    public function test_a_colleague_page_shows_the_team_profile(): void
    {
        $this->enableBundle('team-directory');
        $colleague = $this->colleague($this->a, 'Colleague Visible');
        TeamProfile::create([
            'user_id' => $colleague->id, 'bio' => 'Loves coffee', 'skills' => 'Barista', 'languages_spoken' => 'Français, English', 'hobbies' => 'Climbing',
        ]);

        $this->actingAs($this->viewer)->get('/team-directory/'.$colleague->id)
            ->assertOk()
            ->assertSee('Colleague Visible')
            ->assertSee('Loves coffee')->assertSee('Barista')->assertSee('Français, English')->assertSee('Climbing');
    }

    public function test_an_empty_profile_says_so(): void
    {
        $this->enableBundle('team-directory');
        $colleague = $this->colleague($this->a, 'Colleague Visible');

        $this->actingAs($this->viewer)->get('/team-directory/'.$colleague->id)
            ->assertOk()->assertSee('has not filled in their team profile yet');
    }

    public function test_only_shared_contact_details_are_shown(): void
    {
        $this->enableBundle('team-directory');
        $colleague = $this->colleague($this->a, 'Colleague Visible', ['email' => 'visible@example.test', 'share_email' => true, 'share_phone' => false, 'share_mobile_phone' => false]);
        EmployeeProfile::factory()->create(['user_id' => $colleague->id, 'phone' => '0111111111', 'mobile_phone' => '0622222222', 'address' => '12 Secret Street']);

        $this->actingAs($this->viewer)->get('/team-directory/'.$colleague->id)
            ->assertOk()
            ->assertSee('visible@example.test')
            ->assertDontSee('0111111111')->assertDontSee('0622222222')->assertDontSee('12 Secret Street');

        $colleague->update(['share_phone' => true, 'share_mobile_phone' => true]);

        $this->get('/team-directory/'.$colleague->id)->assertSee('0111111111')->assertSee('0622222222')->assertDontSee('12 Secret Street');
    }

    public function test_a_manager_of_the_store_sees_the_contact_details_that_are_not_shared(): void
    {
        $this->enableBundle('team-directory');
        $manager = $this->managerOf($this->a);
        $this->a->members()->attach($manager);
        $colleague = $this->colleague($this->a, 'Colleague Visible', ['email' => 'visible@example.test']);
        EmployeeProfile::factory()->create(['user_id' => $colleague->id, 'phone' => '0111111111', 'mobile_phone' => '0622222222']);

        $this->actingAs($manager)->get('/team-directory/'.$colleague->id)
            ->assertOk()->assertSee('visible@example.test')->assertSee('0111111111')->assertSee('0622222222');
    }

    public function test_a_manager_of_another_store_gets_no_extra_visibility(): void
    {
        $this->enableBundle('team-directory');
        $manager = $this->managerOf($this->b);
        $this->b->members()->attach($manager);
        $this->a->members()->attach($manager);
        $colleague = $this->colleague($this->a, 'Colleague Visible', ['email' => 'visible@example.test']);

        $this->actingAs($manager)->get('/team-directory/'.$colleague->id)->assertOk()->assertDontSee('visible@example.test');
    }

    public function test_pages_of_people_outside_the_rules_are_not_found(): void
    {
        $this->enableBundle('team-directory');
        $elsewhere = $this->colleague($this->b, 'Stranger Elsewhere');
        $inactive = $this->colleague($this->a, 'Inactive Account', ['is_active' => false]);
        $anonymized = User::factory()->anonymized()->create();
        $this->a->members()->attach($anonymized);
        $hidden = $this->colleague($this->a, 'Colleague Hidden');
        TeamProfile::create(['user_id' => $hidden->id, 'show_in_directory' => false]);
        $owner = $this->owner();

        $this->actingAs($this->viewer);
        foreach ([$elsewhere, $inactive, $anonymized, $hidden, $owner] as $user) {
            $this->get('/team-directory/'.$user->id)->assertNotFound();
        }
        $this->get('/team-directory/999999')->assertNotFound();
    }

    public function test_you_can_always_see_your_own_page_even_when_hidden(): void
    {
        $this->enableBundle('team-directory');
        TeamProfile::create(['user_id' => $this->viewer->id, 'bio' => 'My own bio', 'show_in_directory' => false]);

        $this->actingAs($this->viewer)->get('/team-directory/'.$this->viewer->id)
            ->assertOk()->assertSee('My own bio')->assertSee('Edit my team profile');
    }

    public function test_the_owner_sees_the_page_of_anyone_in_a_store(): void
    {
        $this->enableBundle('team-directory');
        $colleague = $this->colleague($this->b, 'Bert Beta', ['email' => 'bert@example.test']);

        $this->actingAs($this->owner())->get('/team-directory/'.$colleague->id)->assertOk()->assertSee('Bert Beta')->assertSee('bert@example.test');
    }

    // -- Mon profil d'équipe -------------------------------------------------------------------------------------

    public function test_a_user_writes_their_own_team_profile_and_the_change_is_logged_without_the_text(): void
    {
        $this->enableBundle('team-directory');

        Livewire::actingAs($this->viewer)->test('team-directory.edit-profile')
            ->set('bio', '  Hello there  ')
            ->set('skills', 'Cooking')
            ->set('languages_spoken', '')
            ->set('hobbies', 'Chess')
            ->set('show_in_directory', false)
            ->call('save')
            ->assertHasNoErrors();

        $profile = TeamProfile::where('user_id', $this->viewer->id)->firstOrFail();
        $this->assertSame('Hello there', $profile->bio);
        $this->assertSame('Cooking', $profile->skills);
        $this->assertNull($profile->languages_spoken);
        $this->assertFalse($profile->show_in_directory);

        $entry = ActivityEntry::where('action', 'team-directory.profile_updated')->firstOrFail();
        $this->assertSame($this->viewer->id, $entry->actor_id);
        $this->assertStringNotContainsString('Hello there', json_encode($entry->properties));

        // Rien n'a changé : pas de nouvelle ligne de journal.
        Livewire::test('team-directory.edit-profile')->call('save');
        $this->assertSame(1, ActivityEntry::where('action', 'team-directory.profile_updated')->count());
    }

    public function test_the_edit_form_is_prefilled_and_validated(): void
    {
        $this->enableBundle('team-directory');
        TeamProfile::create(['user_id' => $this->viewer->id, 'bio' => 'Old bio', 'show_in_directory' => true]);

        Livewire::actingAs($this->viewer)->test('team-directory.edit-profile')
            ->assertSet('bio', 'Old bio')
            ->set('bio', str_repeat('x', 2001))->call('save')->assertHasErrors(['bio'])
            ->set('bio', 'Fine')->set('skills', str_repeat('x', 501))->call('save')->assertHasErrors(['skills']);

        $this->assertSame('Old bio', TeamProfile::where('user_id', $this->viewer->id)->value('bio'));
    }

    public function test_the_edit_page_only_changes_the_profile_of_the_logged_in_user(): void
    {
        $this->enableBundle('team-directory');
        $other = $this->colleague($this->a, 'Colleague Visible');
        TeamProfile::create(['user_id' => $other->id, 'bio' => 'Untouched']);

        Livewire::actingAs($this->viewer)->test('team-directory.edit-profile')->set('bio', 'Mine')->call('save');

        $this->assertSame('Untouched', TeamProfile::where('user_id', $other->id)->value('bio'));
        $this->assertSame('Mine', TeamProfile::where('user_id', $this->viewer->id)->value('bio'));
    }

    public function test_the_profile_text_is_escaped_on_the_page(): void
    {
        $this->enableBundle('team-directory');
        $colleague = $this->colleague($this->a, 'Colleague Visible');
        TeamProfile::create(['user_id' => $colleague->id, 'bio' => '<script>alert(1)</script>']);

        $this->actingAs($this->viewer)->get('/team-directory/'.$colleague->id)
            ->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    // -- Données -------------------------------------------------------------------------------------------------

    public function test_anonymising_an_account_erases_its_team_profile(): void
    {
        $this->enableBundle('team-directory');
        $colleague = $this->colleague($this->a, 'Colleague Visible');
        TeamProfile::create(['user_id' => $colleague->id, 'bio' => 'Personal text']);
        $other = $this->colleague($this->a, 'Other');
        TeamProfile::create(['user_id' => $other->id, 'bio' => 'Kept']);

        app(AnonymizeUser::class)->handle($colleague);

        $this->assertDatabaseMissing('team_directory_profiles', ['user_id' => $colleague->id]);
        $this->assertDatabaseHas('team_directory_profiles', ['user_id' => $other->id]);
    }

    public function test_disabling_the_bundle_keeps_the_data(): void
    {
        $this->enableBundle('team-directory');
        TeamProfile::create(['user_id' => $this->viewer->id, 'bio' => 'Kept']);

        app(BundleManager::class)->disable('team-directory');

        $this->assertDatabaseHas('team_directory_profiles', ['user_id' => $this->viewer->id, 'bio' => 'Kept']);
    }
}
