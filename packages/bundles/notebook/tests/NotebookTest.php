<?php

namespace Kintai\Bundles\Notebook\Tests;

use App\Bundles\BundleManager;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\Notebook\Models\NotebookEntry;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Carnet de notes : droits par magasin, notes de toute l'organisation, épinglage, expiration, notifications, widgets,
 * fonctionnalité de magasin, bundle désactivé.
 */
class NotebookTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
    }

    /**
     * Utilisateur avec un rôle sur mesure (droits du carnet) sur les magasins donnés, membre de ces magasins.
     *
     * @param  list<string>  $permissions
     * @param  list<Store>  $stores
     * @param  list<string>  $allStores
     */
    private function userWith(array $permissions, array $stores, array $allStores = [], string $name = 'Someone'): User
    {
        $role = $this->customRole($permissions, $allStores);
        $user = User::factory()->create(['name' => $name]);

        foreach ($stores as $store) {
            $store->members()->attach($user);
            $user->assignRole($role, $store);
        }

        return $user;
    }

    private function note(?Store $store, User $author, array $attributes = []): NotebookEntry
    {
        return NotebookEntry::create(['author_id' => $author->id, 'store_id' => $store?->id, 'content' => 'A note', 'pinned' => false, ...$attributes]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('notebook');

        $this->assertTrue(app(PermissionRegistry::class)->has('notebook.view'));
        $this->assertTrue(app(PermissionRegistry::class)->has('notebook.create'));
        $this->assertTrue(app(PermissionRegistry::class)->has('notebook.manage'));
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('notebook'));
        $this->assertTrue(app(NavigationRegistry::class)->has('notebook'));
        $this->assertTrue(app(DashboardWidgetRegistry::class)->has('notebook.manager'));
        $this->assertTrue(app(DashboardWidgetRegistry::class)->has('notebook.employee'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('notebook_entry_created'));
        $this->assertTrue(app(ActivityActionRegistry::class)->has('notebook.created'));
    }

    public function test_a_disabled_bundle_is_absent_everywhere_but_keeps_its_table(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('notebook.view'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('notebook'));
        $this->assertFalse(app(NavigationRegistry::class)->has('notebook'));
        $this->assertFalse(app(DashboardWidgetRegistry::class)->has('notebook.manager'));
        $this->assertFalse(app(NotificationTypeRegistry::class)->has('notebook_entry_created'));

        $this->actingAs($this->owner())->get('/notebook')->assertNotFound();
        $this->assertTrue(Schema::hasTable('notebook_entries'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableBundle('notebook');

        $this->get('/notebook')->assertRedirect(route('login'));
    }

    // -- Voir ----------------------------------------------------------------------------------------------------

    public function test_the_page_needs_the_view_permission(): void
    {
        $this->enableBundle('notebook');
        $nobody = $this->employeeOf($this->a);

        $this->actingAs($nobody)->get('/notebook')->assertForbidden();
        $this->actingAs($this->userWith(['notebook.view'], [$this->a]))->get('/notebook')->assertOk();
    }

    public function test_notes_are_limited_to_the_stores_of_the_readers_permission_plus_the_whole_organization(): void
    {
        $this->enableBundle('notebook');
        $author = $this->owner();
        $this->note($this->a, $author, ['content' => 'Note for Alpha']);
        $this->note($this->b, $author, ['content' => 'Note for Beta']);
        $this->note(null, $author, ['content' => 'Note for everyone']);

        Livewire::actingAs($this->userWith(['notebook.view'], [$this->a]))->test('notebook.index')
            ->assertSee('Note for Alpha')->assertSee('Note for everyone')->assertDontSee('Note for Beta');

        Livewire::actingAs($this->userWith(['notebook.view'], [$this->a], ['notebook.view']))->test('notebook.index')
            ->assertSee('Note for Alpha')->assertSee('Note for Beta')->assertSee('Note for everyone');
    }

    public function test_pinned_notes_come_first(): void
    {
        $this->enableBundle('notebook');
        $author = $this->owner();
        $this->note($this->a, $author, ['content' => 'Old pinned note', 'pinned' => true, 'created_at' => now()->subDays(5)]);
        $this->note($this->a, $author, ['content' => 'Recent note']);

        Livewire::actingAs($this->userWith(['notebook.view'], [$this->a]))->test('notebook.index')
            ->assertSeeInOrder(['Old pinned note', 'Recent note']);
    }

    public function test_expired_notes_are_hidden_unless_requested(): void
    {
        $this->enableBundle('notebook');
        $author = $this->owner();
        $this->note($this->a, $author, ['content' => 'Expired note', 'expires_on' => now()->subDay()->toDateString()]);
        $this->note($this->a, $author, ['content' => 'Last day note', 'expires_on' => NotebookEntry::today()]);
        $this->note($this->a, $author, ['content' => 'Future note', 'expires_on' => now()->addDays(3)->toDateString()]);

        Livewire::actingAs($this->userWith(['notebook.view'], [$this->a]))->test('notebook.index')
            ->assertDontSee('Expired note')->assertSee('Last day note')->assertSee('Future note')
            ->set('showExpired', true)->assertSee('Expired note');
    }

    public function test_a_store_without_the_feature_is_left_out_and_without_any_store_the_page_is_gone(): void
    {
        $this->enableBundle('notebook');
        $author = $this->owner();
        $this->note($this->a, $author, ['content' => 'Note for Alpha']);
        $this->note($this->b, $author, ['content' => 'Note for Beta']);
        $reader = $this->userWith(['notebook.view'], [$this->a, $this->b]);
        StoreFeature::create(['store_id' => $this->b->id, 'feature' => 'notebook', 'enabled' => false]);

        Livewire::actingAs($reader)->test('notebook.index')->assertSee('Note for Alpha')->assertDontSee('Note for Beta');

        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'notebook', 'enabled' => false]);

        $this->actingAs($reader)->get('/notebook')->assertNotFound();
    }

    public function test_viewing_the_page_marks_the_publication_notifications_as_read(): void
    {
        $this->enableBundle('notebook');
        $author = $this->userWith(['notebook.view', 'notebook.create'], [$this->a], name: 'Writer');
        $reader = $this->userWith(['notebook.view'], [$this->a], name: 'Reader');

        Livewire::actingAs($author)->test('notebook.index')->set('storeId', (string) $this->a->id)->set('content', 'Hello team')->call('publish');
        $this->assertSame(1, $reader->unreadNotifications()->count());

        Livewire::actingAs($reader)->test('notebook.index');

        $this->assertSame(0, $reader->fresh()->unreadNotifications()->count());
    }

    // -- Publier -------------------------------------------------------------------------------------------------

    public function test_a_note_is_published_for_a_store_and_logged(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);

        Livewire::actingAs($writer)->test('notebook.index')
            ->set('storeId', (string) $this->a->id)
            ->set('content', '  Staff meeting at 10  ')
            ->set('expiresOn', now()->addWeek()->toDateString())
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('content', '')
            ->assertSee('Staff meeting at 10');

        $entry = NotebookEntry::firstOrFail();
        $this->assertSame('Staff meeting at 10', $entry->content);
        $this->assertSame($this->a->id, $entry->store_id);
        $this->assertSame($writer->id, $entry->author_id);
        $this->assertFalse($entry->pinned);
        $this->assertNotNull(ActivityEntry::where('action', 'notebook.created')->where('actor_id', $writer->id)->first());
    }

    public function test_publishing_validates_the_content_and_the_date(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);

        Livewire::actingAs($writer)->test('notebook.index')
            ->set('storeId', (string) $this->a->id)
            ->set('content', '   ')->call('publish')->assertHasErrors(['content'])
            ->set('content', 'Fine')->set('expiresOn', 'tomorrow')->call('publish')->assertHasErrors(['expiresOn']);

        $this->assertSame(0, NotebookEntry::count());
    }

    public function test_a_store_scoped_writer_cannot_publish_to_another_store_or_to_the_whole_organization(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);
        $this->b->members()->attach($writer);

        Livewire::actingAs($writer)->test('notebook.index')
            ->set('storeId', (string) $this->b->id)->set('content', 'Nope')->call('publish')->assertForbidden();
        Livewire::actingAs($writer)->test('notebook.index')
            ->set('storeId', '')->set('content', 'Nope')->call('publish')->assertForbidden();

        $this->assertSame(0, NotebookEntry::count());
    }

    public function test_a_writer_without_the_create_permission_cannot_publish(): void
    {
        $this->enableBundle('notebook');
        $reader = $this->userWith(['notebook.view'], [$this->a]);

        Livewire::actingAs($reader)->test('notebook.index')
            ->assertDontSee('data-test="new-note-form"', false)
            ->set('storeId', (string) $this->a->id)->set('content', 'Nope')->call('publish')->assertForbidden();
    }

    public function test_a_writer_with_an_unrestricted_permission_publishes_for_the_whole_organization(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a], ['notebook.view', 'notebook.create']);

        Livewire::actingAs($writer)->test('notebook.index')
            ->assertSet('storeId', '')
            ->set('content', 'To everybody')->call('publish')->assertHasNoErrors();

        $this->assertNull(NotebookEntry::firstOrFail()->store_id);
    }

    public function test_the_owner_publishes_everywhere(): void
    {
        $this->enableBundle('notebook');

        Livewire::actingAs($this->owner())->test('notebook.index')
            ->set('storeId', (string) $this->b->id)->set('content', 'From the owner')->call('publish')->assertHasNoErrors();

        $this->assertSame($this->b->id, NotebookEntry::firstOrFail()->store_id);
    }

    public function test_publishing_notifies_the_readers_of_the_store_but_not_the_author_nor_outsiders(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a], name: 'Writer Person');
        $reader = $this->userWith(['notebook.view'], [$this->a]);
        $nonReader = $this->employeeOf($this->a);
        $otherStore = $this->userWith(['notebook.view'], [$this->b]);

        Livewire::actingAs($writer)->test('notebook.index')
            ->set('storeId', (string) $this->a->id)->set('content', 'Big news')->call('publish');

        $this->assertSame(1, $reader->notifications()->count());
        $notification = $reader->notifications()->first();
        $this->assertInstanceOf(DatabaseNotification::class, $notification);
        $this->assertSame('notebook_entry_created', $notification->type);
        $this->assertSame(NotebookEntry::firstOrFail()->id, $notification->data['reference_id']);
        $this->assertStringContainsString('highlight=', $notification->data['link']);
        $this->assertSame(0, $writer->notifications()->count());
        $this->assertSame(0, $nonReader->notifications()->count());
        $this->assertSame(0, $otherStore->notifications()->count());
    }

    public function test_an_organization_wide_note_notifies_every_reader(): void
    {
        $this->enableBundle('notebook');
        $reader = $this->userWith(['notebook.view'], [$this->a]);
        $otherReader = $this->userWith(['notebook.view'], [$this->b]);
        $nonReader = $this->employeeOf($this->b);

        Livewire::actingAs($this->owner())->test('notebook.index')->set('storeId', '')->set('content', 'For all')->call('publish');

        $this->assertSame(1, $reader->notifications()->count());
        $this->assertSame(1, $otherReader->notifications()->count());
        $this->assertSame(0, $nonReader->notifications()->count());
    }

    // -- Modifier, supprimer, épingler ---------------------------------------------------------------------------

    public function test_the_author_edits_and_deletes_their_own_note(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);
        $entry = $this->note($this->a, $writer, ['content' => 'First version']);

        Livewire::actingAs($writer)->test('notebook.index')
            ->call('startEditing', $entry->id)->assertSet('editContent', 'First version')
            ->set('editContent', 'Second version')->set('editExpiresOn', now()->addDay()->toDateString())->call('saveEditing')
            ->assertHasNoErrors()->assertSee('Second version')
            ->call('confirmDeletion', $entry->id)->assertSet('deletingId', $entry->id)->call('delete');

        $this->assertDatabaseMissing('notebook_entries', ['id' => $entry->id]);
        $this->assertNotNull(ActivityEntry::where('action', 'notebook.updated')->first());
        $this->assertNotNull(ActivityEntry::where('action', 'notebook.deleted')->first());
    }

    public function test_editing_an_empty_text_is_refused(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);
        $entry = $this->note($this->a, $writer, ['content' => 'Keep me']);

        Livewire::actingAs($writer)->test('notebook.index')
            ->call('startEditing', $entry->id)->set('editContent', '')->call('saveEditing')->assertHasErrors(['editContent']);

        $this->assertSame('Keep me', $entry->fresh()->content);
    }

    public function test_someone_else_cannot_edit_delete_or_pin_a_note_without_the_manage_permission(): void
    {
        $this->enableBundle('notebook');
        $author = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);
        $colleague = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);
        $entry = $this->note($this->a, $author);

        Livewire::actingAs($colleague)->test('notebook.index')->call('startEditing', $entry->id)->assertForbidden();
        Livewire::actingAs($colleague)->test('notebook.index')->call('confirmDeletion', $entry->id)->assertForbidden();
        Livewire::actingAs($colleague)->test('notebook.index')->call('togglePin', $entry->id)->assertForbidden();

        $this->assertDatabaseHas('notebook_entries', ['id' => $entry->id, 'pinned' => false]);
    }

    public function test_an_author_who_lost_the_create_permission_cannot_edit_anymore(): void
    {
        $this->enableBundle('notebook');
        $reader = $this->userWith(['notebook.view'], [$this->a]);
        $entry = $this->note($this->a, $reader);

        Livewire::actingAs($reader)->test('notebook.index')->call('startEditing', $entry->id)->assertForbidden();
    }

    public function test_a_manager_of_the_store_edits_deletes_and_pins_its_notes(): void
    {
        $this->enableBundle('notebook');
        $author = $this->userWith(['notebook.view', 'notebook.create'], [$this->a]);
        $manager = $this->userWith(['notebook.view', 'notebook.manage'], [$this->a]);
        $entry = $this->note($this->a, $author);

        Livewire::actingAs($manager)->test('notebook.index')
            ->call('togglePin', $entry->id)
            ->call('startEditing', $entry->id)->set('editContent', 'Moderated')->call('saveEditing');

        $this->assertTrue($entry->fresh()->pinned);
        $this->assertSame('Moderated', $entry->fresh()->content);

        Livewire::actingAs($manager)->test('notebook.index')->call('togglePin', $entry->id);
        $this->assertFalse($entry->fresh()->pinned);

        Livewire::actingAs($manager)->test('notebook.index')->call('confirmDeletion', $entry->id)->call('delete');
        $this->assertDatabaseMissing('notebook_entries', ['id' => $entry->id]);
    }

    public function test_a_manager_of_one_store_cannot_touch_the_notes_of_another_store_or_of_the_whole_organization(): void
    {
        $this->enableBundle('notebook');
        $owner = $this->owner();
        $manager = $this->userWith(['notebook.view', 'notebook.manage'], [$this->a, $this->b]);
        $manager->removeRole($this->role('employee'), $this->b);
        $global = $this->note(null, $owner);
        $inB = $this->note($this->b, $owner);
        $inA = $this->note($this->a, $owner);

        Livewire::actingAs($manager)->test('notebook.index')->call('togglePin', $global->id)->assertForbidden();
        Livewire::actingAs($manager)->test('notebook.index')->call('startEditing', $global->id)->assertForbidden();
        Livewire::actingAs($manager)->test('notebook.index')->call('togglePin', $inA->id)->assertOk();

        // La note de B existe mais le gérant (rôle d'un seul magasin) ne la voit pas.
        $this->assertTrue($inA->fresh()->pinned);
        $this->assertFalse($global->fresh()->pinned);
        $this->assertFalse($inB->fresh()->pinned);
    }

    public function test_a_manager_with_an_unrestricted_permission_manages_the_whole_organization_notes(): void
    {
        $this->enableBundle('notebook');
        $manager = $this->userWith(['notebook.view', 'notebook.manage'], [$this->a], ['notebook.manage']);
        $global = $this->note(null, $this->owner());

        Livewire::actingAs($manager)->test('notebook.index')->call('togglePin', $global->id);

        $this->assertTrue($global->fresh()->pinned);
    }

    public function test_a_note_that_cannot_be_seen_is_not_found(): void
    {
        $this->enableBundle('notebook');
        $reader = $this->userWith(['notebook.view', 'notebook.manage'], [$this->a]);
        $hidden = $this->note($this->b, $this->owner());

        Livewire::actingAs($reader)->test('notebook.index')->call('togglePin', $hidden->id)->assertNotFound();
        $this->assertFalse($hidden->fresh()->pinned);
    }

    public function test_the_text_of_a_note_is_escaped(): void
    {
        $this->enableBundle('notebook');
        $this->note($this->a, $this->owner(), ['content' => '<script>alert(1)</script>']);

        $this->actingAs($this->userWith(['notebook.view'], [$this->a]))->get('/notebook')
            ->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    // -- Widgets -------------------------------------------------------------------------------------------------

    public function test_the_widgets_show_the_five_first_visible_notes(): void
    {
        $this->enableBundle('notebook');
        $author = $this->owner();
        foreach (range(1, 6) as $i) {
            $this->note($this->a, $author, ['content' => "Plain note {$i}", 'created_at' => now()->subMinutes(10 - $i)]);
        }
        $this->note($this->a, $author, ['content' => 'Pinned oldest', 'pinned' => true, 'created_at' => now()->subYear()]);
        $this->note($this->a, $author, ['content' => 'Expired one', 'expires_on' => now()->subDay()->toDateString()]);
        $this->note($this->b, $author, ['content' => 'Other store']);
        $reader = $this->userWith(['notebook.view'], [$this->a]);

        Livewire::actingAs($reader)->test('notebook.manager-widget')
            ->assertSee('Team notes')
            ->assertSee('Pinned oldest')->assertSee('Plain note 6')->assertSee('Plain note 3')
            ->assertDontSee('Plain note 2')->assertDontSee('Expired one')->assertDontSee('Other store');

        Livewire::actingAs($reader)->test('notebook.employee-widget')->assertSee('Notes from my stores')->assertSee('Pinned oldest');
    }

    public function test_the_widget_shows_up_on_the_dashboard_of_someone_who_can_read_the_notebook(): void
    {
        $this->enableBundle('notebook');
        $this->note($this->a, $this->owner(), ['content' => 'Dashboard note']);

        $this->actingAs($this->userWith(['notebook.view'], [$this->a]))->get(route('dashboard.employee'))
            ->assertOk()->assertSee('Dashboard note');
        $this->actingAs($this->employeeOf($this->a))->get(route('dashboard.employee'))->assertOk()->assertDontSee('Dashboard note');
    }

    public function test_the_menu_entry_follows_the_view_permission(): void
    {
        $this->enableBundle('notebook');

        $this->actingAs($this->userWith(['notebook.view'], [$this->a]))->get(route('dashboard'))->assertSee(route('notebook.index'), false);
        $this->actingAs($this->employeeOf($this->a))->get(route('dashboard'))->assertDontSee(route('notebook.index'), false);
    }

    public function test_disabling_the_bundle_keeps_the_notes(): void
    {
        $this->enableBundle('notebook');
        $entry = $this->note($this->a, $this->owner());

        app(BundleManager::class)->disable('notebook');

        $this->assertDatabaseHas('notebook_entries', ['id' => $entry->id]);
    }
}
