<?php

namespace Kintai\Bundles\Notebook\Tests;

use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\Notebook\Models\NotebookEntry;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API du carnet : `/api/v1/notebook/entries`, mêmes droits que la page, mêmes erreurs que l'API du Core.
 */
class NotebookApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

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
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, Store $store, bool $unrestricted = false): User
    {
        $role = $this->customRole($permissions, $unrestricted ? $permissions : []);
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($role, $store);

        return $user;
    }

    private function note(?Store $store, User $author, array $attributes = []): NotebookEntry
    {
        return NotebookEntry::create(['author_id' => $author->id, 'store_id' => $store?->id, 'content' => 'A note', 'pinned' => false, ...$attributes]);
    }

    public function test_the_routes_exist_only_when_the_bundle_is_enabled(): void
    {
        $user = $this->userWith(['notebook.view'], $this->a);

        $this->actingWithToken($user)->getJson('/api/v1/notebook/entries')->assertNotFound()->assertJson(['code' => 'not_found']);

        $this->enableBundle('notebook');

        $this->actingWithToken($user)->getJson('/api/v1/notebook/entries')->assertOk();
        $this->assertSame(url('/api/v1/notebook/entries'), route('api.v1.notebook.entries.index'));
    }

    public function test_the_api_needs_a_token_and_the_core_error_format_applies(): void
    {
        $this->enableBundle('notebook');

        $this->getJson('/api/v1/notebook/entries')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->actingWithToken($this->employeeOf($this->a))->getJson('/api/v1/notebook/entries')
            ->assertForbidden()->assertJson(['code' => 'forbidden']);
    }

    public function test_an_inactive_account_loses_access(): void
    {
        $this->enableBundle('notebook');
        $user = $this->userWith(['notebook.view'], $this->a);
        $token = $this->tokenFor($user);
        $user->update(['is_active' => false]);

        $this->flushHeaders();
        $this->withToken($token)->getJson('/api/v1/notebook/entries')->assertStatus(401);
    }

    public function test_the_list_follows_the_scope_and_the_filters(): void
    {
        $this->enableBundle('notebook');
        $owner = $this->owner();
        $this->note($this->a, $owner, ['content' => 'Alpha note']);
        $this->note($this->b, $owner, ['content' => 'Beta note']);
        $this->note(null, $owner, ['content' => 'Everyone note', 'pinned' => true]);
        $this->note($this->a, $owner, ['content' => 'Old note', 'expires_on' => now()->subDays(2)->toDateString()]);
        $reader = $this->userWith(['notebook.view'], $this->a);

        $response = $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries')->assertOk();
        $this->assertSame(['Everyone note', 'Alpha note'], array_column($response->json('data'), 'content'));
        $this->assertSame(2, $response->json('meta.total'));

        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries?include_expired=1')->assertJsonCount(3, 'data');
        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries?pinned=1')->assertJsonCount(1, 'data');
        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries?store_id='.$this->b->id)->assertJsonCount(0, 'data');
    }

    public function test_show_returns_the_note_or_not_found(): void
    {
        $this->enableBundle('notebook');
        $owner = $this->owner();
        $visible = $this->note($this->a, $owner, ['content' => 'Alpha note']);
        $hidden = $this->note($this->b, $owner);
        $reader = $this->userWith(['notebook.view'], $this->a);

        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries/'.$visible->id)
            ->assertOk()->assertJsonPath('data.content', 'Alpha note')->assertJsonPath('data.store_name', 'Alpha')
            ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_pin', false);
        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries/'.$hidden->id)->assertNotFound();
        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries/999')->assertNotFound();
    }

    public function test_a_note_is_created_with_the_page_rules(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], $this->a);
        $reader = $this->userWith(['notebook.view'], $this->a);

        $this->actingWithToken($writer)->postJson('/api/v1/notebook/entries', [
            'content' => ' Hello ', 'store_id' => $this->a->id, 'expires_on' => now()->addWeek()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.content', 'Hello')->assertJsonPath('data.can_edit', true);

        $entry = NotebookEntry::firstOrFail();
        $this->assertSame($writer->id, $entry->author_id);
        $this->assertFalse($entry->pinned);
        $this->assertSame(1, $reader->notifications()->count());

        // Hors périmètre : autre magasin, toute l'organisation sans droit illimité.
        $this->actingWithToken($writer)->postJson('/api/v1/notebook/entries', ['content' => 'No', 'store_id' => $this->b->id])->assertForbidden();
        $this->actingWithToken($writer)->postJson('/api/v1/notebook/entries', ['content' => 'No'])->assertForbidden();
        // Sans droit de création.
        $this->actingWithToken($reader)->postJson('/api/v1/notebook/entries', ['content' => 'No', 'store_id' => $this->a->id])->assertForbidden();
        $this->assertSame(1, NotebookEntry::count());
    }

    public function test_an_unrestricted_writer_publishes_for_the_whole_organization(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], $this->a, true);

        $this->actingWithToken($writer)->postJson('/api/v1/notebook/entries', ['content' => 'To all'])
            ->assertCreated()->assertJsonPath('data.store_id', null);
    }

    public function test_validation_errors_use_the_core_format(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], $this->a);

        $this->actingWithToken($writer)->postJson('/api/v1/notebook/entries', ['store_id' => $this->a->id, 'content' => '', 'expires_on' => 'soon'])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors(['content', 'expires_on']);
    }

    public function test_only_the_text_and_the_expiry_can_be_updated(): void
    {
        $this->enableBundle('notebook');
        $writer = $this->userWith(['notebook.view', 'notebook.create'], $this->a);
        $entry = $this->note($this->a, $writer, ['content' => 'First', 'expires_on' => now()->addDay()->toDateString()]);

        $this->actingWithToken($writer)->putJson('/api/v1/notebook/entries/'.$entry->id, [
            'content' => 'Second', 'store_id' => $this->b->id, 'pinned' => true, 'author_id' => 999,
        ])->assertOk()->assertJsonPath('data.content', 'Second');

        $fresh = $entry->fresh();
        $this->assertSame($this->a->id, $fresh->store_id);
        $this->assertFalse($fresh->pinned);
        $this->assertSame($writer->id, $fresh->author_id);
        $this->assertNotNull($fresh->expires_on, 'An absent key keeps its value.');

        $this->actingWithToken($writer)->putJson('/api/v1/notebook/entries/'.$entry->id, ['expires_on' => null])->assertOk();
        $this->assertNull($entry->fresh()->expires_on);
    }

    public function test_deleting_and_pinning_need_the_right_to_do_so(): void
    {
        $this->enableBundle('notebook');
        $author = $this->userWith(['notebook.view', 'notebook.create'], $this->a);
        $colleague = $this->userWith(['notebook.view', 'notebook.create'], $this->a);
        $manager = $this->userWith(['notebook.view', 'notebook.manage'], $this->a);
        $entry = $this->note($this->a, $author);

        $this->actingWithToken($colleague)->putJson('/api/v1/notebook/entries/'.$entry->id, ['content' => 'x'])->assertForbidden();
        $this->actingWithToken($colleague)->deleteJson('/api/v1/notebook/entries/'.$entry->id)->assertForbidden();
        $this->actingWithToken($author)->postJson('/api/v1/notebook/entries/'.$entry->id.'/pin')->assertForbidden();

        $this->actingWithToken($manager)->postJson('/api/v1/notebook/entries/'.$entry->id.'/pin')->assertOk()->assertJsonPath('data.pinned', true);
        $this->actingWithToken($manager)->postJson('/api/v1/notebook/entries/'.$entry->id.'/pin')->assertOk()->assertJsonPath('data.pinned', false);

        $this->actingWithToken($author)->deleteJson('/api/v1/notebook/entries/'.$entry->id)->assertNoContent();
        $this->assertDatabaseMissing('notebook_entries', ['id' => $entry->id]);
    }

    public function test_the_api_honours_the_store_feature(): void
    {
        $this->enableBundle('notebook');
        $reader = $this->userWith(['notebook.view'], $this->a);
        $entry = $this->note($this->a, $this->owner());
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'notebook', 'enabled' => false]);

        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries')->assertJsonCount(0, 'data');
        $this->actingWithToken($reader)->getJson('/api/v1/notebook/entries/'.$entry->id)->assertNotFound();
    }
}
