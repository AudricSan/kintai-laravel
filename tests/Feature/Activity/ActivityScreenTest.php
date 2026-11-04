<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ActivityScreenTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $alpha;

    private Store $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->alpha = Store::factory()->create(['name' => 'Alpha']);
        $this->beta = Store::factory()->create(['name' => 'Beta']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes = []): ActivityEntry
    {
        return ActivityEntry::factory()->create($attributes);
    }

    /**
     * @return list<string>
     */
    private function rowActions(Testable $component): array
    {
        /** @var LengthAwarePaginator<int, ActivityEntry> $page */
        $page = $component->viewData('page');

        return $page->getCollection()->pluck('action')->all();
    }

    public function test_access_is_limited_to_users_who_can_view_stores(): void
    {
        $this->get(route('activity.index'))->assertRedirect(route('login'));

        $this->actingAs($this->employeeOf($this->alpha))->get(route('activity.index'))->assertForbidden();
        $this->actingAs($this->employeeOf($this->alpha))->get(route('activity.export'))->assertForbidden();
        $this->actingAs($this->managerOf($this->alpha))->get(route('activity.index'))->assertOk();
        $this->actingAs($this->owner())->get(route('activity.index'))->assertOk();
    }

    public function test_the_page_lists_entries_with_label_target_store_details_and_ip(): void
    {
        $actor = User::factory()->create(['name' => 'Olivia']);
        $this->entry([
            'actor_id' => $actor->id, 'action' => 'store.created', 'subject_type' => (new Store)->getMorphClass(), 'subject_id' => $this->alpha->id,
            'store_id' => $this->alpha->id, 'properties' => ['name' => 'Alpha', 'code' => 'ALP'], 'ip_address' => '198.51.100.7',
        ]);
        $this->entry(['action' => 'bundle.custom', 'properties' => null]);

        $this->actingAs($this->owner());

        Livewire::test('pages::activity.index')
            ->assertSee('Olivia')
            ->assertSee('Store created')
            ->assertSee('Store #'.$this->alpha->id.' (Alpha)')
            ->assertSee('Code')
            ->assertSee('ALP')
            ->assertSee('198.51.100.7')
            ->assertSee('bundle.custom');
    }

    public function test_dates_use_the_instance_timezone(): void
    {
        config(['kintai.timezone' => 'Asia/Tokyo']);
        $this->entry(['created_at' => '2026-11-04 20:30:00']);

        $this->actingAs($this->owner());

        Livewire::test('pages::activity.index')->assertSee('2026-11-05 05:30:00');
    }

    public function test_a_restricted_manager_only_sees_the_entries_of_their_stores(): void
    {
        $this->entry(['action' => 'in.alpha', 'store_id' => $this->alpha->id]);
        $this->entry(['action' => 'in.beta', 'store_id' => $this->beta->id]);
        $this->entry(['action' => 'no.store', 'store_id' => null]);

        $this->actingAs($this->managerOf($this->alpha));
        $component = Livewire::test('pages::activity.index');

        $this->assertSame(['in.alpha'], $this->rowActions($component));
        $component->assertDontSee('in.beta')->assertDontSee('no.store');

        // Filtrer sur un magasin hors périmètre ne révèle rien.
        $this->assertSame([], $this->rowActions($component->set('store', (string) $this->beta->id)));
    }

    public function test_the_owner_sees_everything_including_entries_without_a_store(): void
    {
        $this->entry(['action' => 'in.alpha', 'store_id' => $this->alpha->id]);
        $this->entry(['action' => 'no.store']);

        $this->actingAs($this->owner());

        $this->assertEqualsCanonicalizing(['in.alpha', 'no.store'], $this->rowActions(Livewire::test('pages::activity.index')));
    }

    public function test_filter_options_only_offer_visible_stores_and_users(): void
    {
        $visible = User::factory()->create(['name' => 'Visible Vera']);
        $hidden = User::factory()->create(['name' => 'Hidden Hugo']);
        $this->entry(['actor_id' => $visible->id, 'store_id' => $this->alpha->id]);
        $this->entry(['actor_id' => $hidden->id, 'store_id' => $this->beta->id]);

        $this->actingAs($this->managerOf($this->alpha));

        Livewire::test('pages::activity.index')
            ->assertSee('Alpha')
            ->assertDontSee('Beta')
            ->assertSee('Visible Vera')
            ->assertDontSee('Hidden Hugo');
    }

    public function test_filters_by_action_user_store_and_period(): void
    {
        $vera = User::factory()->create();
        $hugo = User::factory()->create();
        $this->entry(['action' => 'a.one', 'actor_id' => $vera->id, 'store_id' => $this->alpha->id, 'created_at' => '2026-11-01 10:00:00']);
        $this->entry(['action' => 'a.two', 'actor_id' => $hugo->id, 'store_id' => $this->beta->id, 'created_at' => '2026-11-03 10:00:00']);
        $this->entry(['action' => 'a.three', 'actor_id' => $hugo->id, 'store_id' => $this->alpha->id, 'created_at' => '2026-11-05 23:30:00']);

        $this->actingAs($this->owner());
        $component = Livewire::test('pages::activity.index');

        $this->assertSame(['a.two'], $this->rowActions($component->set('action', 'a.two')));
        $component->set('action', '');
        $this->assertEqualsCanonicalizing(['a.two', 'a.three'], $this->rowActions($component->set('actor', (string) $hugo->id)));
        $component->set('actor', '');
        $this->assertEqualsCanonicalizing(['a.one', 'a.three'], $this->rowActions($component->set('store', (string) $this->alpha->id)));
        $component->set('store', '');
        $this->assertSame(['a.two'], $this->rowActions($component->set('from', '2026-11-02')->set('to', '2026-11-04')));
        // La borne de fin couvre toute la journée.
        $this->assertSame(['a.three', 'a.two'], $this->rowActions($component->set('to', '2026-11-05')));
        $this->assertCount(3, $this->rowActions($component->call('resetFilters')));
    }

    public function test_the_list_is_paginated_by_100_newest_first(): void
    {
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['action' => 'bulk.entry', 'created_at' => now()->subMinutes(200 - $i)->toDateTimeString()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('activity_entries')->insert($chunk);
        }

        $this->actingAs($this->owner());
        $component = Livewire::test('pages::activity.index');

        /** @var LengthAwarePaginator<int, ActivityEntry> $page */
        $page = $component->viewData('page');
        $this->assertSame(105, $page->total());
        $this->assertCount(100, $page->items());
        $this->assertSame(105, $page->first()->id);

        $this->assertCount(5, $component->call('setPage', 2)->viewData('page')->items());
    }

    public function test_export_is_a_semicolon_csv_with_a_utf8_bom(): void
    {
        $actor = User::factory()->create(['name' => 'Olivia']);
        $this->entry([
            'actor_id' => $actor->id, 'action' => 'store.created', 'subject_type' => (new Store)->getMorphClass(), 'subject_id' => $this->alpha->id,
            'store_id' => $this->alpha->id, 'properties' => ['name' => 'Alpha; "quoted"'], 'ip_address' => '198.51.100.7', 'created_at' => '2026-11-04 10:00:00',
        ]);
        $evil = User::factory()->create(['name' => '=HYPERLINK("x")']);
        $this->entry(['action' => 'bundle.custom', 'actor_id' => $evil->id, 'properties' => null]);

        $owner = $this->owner();
        $response = $this->actingAs($owner)->get(route('activity.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = array_values(array_filter(explode("\n", substr($content, 3))));
        $this->assertSame('Date;User;Action;"Action key";Target;Store;Details;"IP address"', $lines[0]);

        $rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), array_slice($lines, 1));
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row[3]] = $row;
        }

        $this->assertSame('2026-11-04 10:00:00', $byKey['store.created'][0]);
        $this->assertSame('Olivia', $byKey['store.created'][1]);
        $this->assertSame('Store created', $byKey['store.created'][2]);
        $this->assertSame('Store #'.$this->alpha->id.' (Alpha; "quoted")', $byKey['store.created'][4]);
        $this->assertSame('Alpha', $byKey['store.created'][5]);
        $this->assertSame('Name : Alpha; "quoted"', $byKey['store.created'][6]);
        $this->assertSame('198.51.100.7', $byKey['store.created'][7]);
        // Raw key for unknown actions, and no formula injection.
        $this->assertSame('bundle.custom', $byKey['bundle.custom'][2]);
        $this->assertSame('\'=HYPERLINK("x")', $byKey['bundle.custom'][1]);
    }

    public function test_export_applies_the_filters_and_the_store_restriction(): void
    {
        $this->entry(['action' => 'in.alpha', 'store_id' => $this->alpha->id]);
        $this->entry(['action' => 'in.alpha.other', 'store_id' => $this->alpha->id]);
        $this->entry(['action' => 'in.beta', 'store_id' => $this->beta->id]);
        $this->entry(['action' => 'no.store']);

        $manager = $this->managerOf($this->alpha);
        $content = $this->actingAs($manager)->get(route('activity.export'))->streamedContent();

        $this->assertStringContainsString('in.alpha', $content);
        $this->assertStringNotContainsString('in.beta', $content);
        $this->assertStringNotContainsString('no.store', $content);

        $filtered = $this->actingAs($manager)->get(route('activity.export', ['action' => 'in.alpha.other']))->streamedContent();
        $this->assertStringContainsString('in.alpha.other', $filtered);
        $this->assertStringNotContainsString('in.alpha;', $filtered);

        $outside = $this->actingAs($manager)->get(route('activity.export', ['store' => $this->beta->id]))->streamedContent();
        $this->assertStringNotContainsString('in.beta', $outside);
    }

    public function test_export_is_limited_to_20000_rows_and_is_itself_logged(): void
    {
        $rows = [];
        for ($i = 0; $i < 20001; $i++) {
            $rows[] = ['action' => 'bulk.entry', 'created_at' => '2026-01-01 00:00:00'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('activity_entries')->insert($chunk);
        }

        $owner = $this->owner();
        $content = $this->actingAs($owner)->get(route('activity.export'))->streamedContent();

        // En-tête + 20 000 lignes, et la ligne de l'export lui-même n'y figure pas.
        $this->assertSame(20001, substr_count($content, "\n"));
        $this->assertStringNotContainsString('activity.exported', $content);

        $logged = ActivityEntry::where('action', 'activity.exported')->sole();
        $this->assertSame($owner->id, $logged->actor_id);
        $this->assertSame(20000, $logged->properties['count']);
    }

    public function test_export_filters_are_logged(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('activity.export', ['action' => 'x.y', 'store' => '']))->streamedContent();

        $this->assertSame(['action' => 'x.y'], ActivityEntry::where('action', 'activity.exported')->sole()->properties['filters']);
    }

    public function test_only_the_owner_can_purge_manually(): void
    {
        $this->entry(['created_at' => now()->subDays(40)]);

        $this->actingAs($this->managerOf($this->alpha));
        Livewire::test('pages::activity.index')->assertDontSee('data-test="purge-activity"', false)->call('confirmPurge')->assertForbidden();

        Livewire::test('pages::activity.index')->call('purge')->assertForbidden();
        $this->assertSame(1, ActivityEntry::count());
    }

    public function test_the_owner_purges_with_a_retention_choice_and_the_purge_is_logged_afterwards(): void
    {
        $this->entry(['action' => 'old.one', 'created_at' => now()->subDays(100)]);
        $this->entry(['action' => 'old.two', 'created_at' => now()->subDays(40)]);
        $this->entry(['action' => 'recent', 'created_at' => now()->subDays(3)]);

        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::activity.index')->set('keepDays', '30')->call('purge')->assertHasNoErrors();

        $this->assertSame(['recent'], ActivityEntry::where('action', '!=', 'activity.purged')->pluck('action')->all());

        $logged = ActivityEntry::where('action', 'activity.purged')->sole();
        $this->assertSame($owner->id, $logged->actor_id);
        $this->assertSame(['kept_days' => 30, 'deleted' => 2], $logged->properties);
    }

    public function test_purging_with_zero_deletes_everything_but_the_purge_record(): void
    {
        $this->entry(['created_at' => now()->subDays(1)]);
        $this->entry(['created_at' => now()]);

        $this->actingAs($this->owner());
        Livewire::test('pages::activity.index')->set('keepDays', '0')->call('purge');

        $this->assertSame(['activity.purged'], ActivityEntry::pluck('action')->all());
        $this->assertSame(2, ActivityEntry::sole()->properties['deleted']);
    }

    public function test_an_unknown_retention_choice_is_refused(): void
    {
        $this->entry(['created_at' => now()->subDays(400)]);

        $this->actingAs($this->owner());
        Livewire::test('pages::activity.index')->set('keepDays', '15')->call('purge')->assertHasErrors('keep_days');

        $this->assertSame(1, ActivityEntry::count());
    }
}
