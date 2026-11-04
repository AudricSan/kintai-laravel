<?php

namespace Tests\Feature\Activity;

use App\Activity\ActivityActionRegistry;
use App\Activity\ActivityDetails;
use App\Models\ActivityEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ActivityMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_automatic_purge_deletes_entries_older_than_the_retention(): void
    {
        config(['kintai.activity.retention_days' => 30]);
        ActivityEntry::factory()->create(['action' => 'old', 'created_at' => now()->subDays(31)]);
        ActivityEntry::factory()->create(['action' => 'recent', 'created_at' => now()->subDays(29)]);

        $this->artisan('kintai:activity:purge')->assertSuccessful();

        $this->assertSame(['recent'], ActivityEntry::pluck('action')->all());
    }

    public function test_a_retention_of_zero_keeps_everything(): void
    {
        config(['kintai.activity.retention_days' => 0]);
        ActivityEntry::factory()->create(['created_at' => now()->subYears(5)]);

        $this->artisan('kintai:activity:purge')->assertSuccessful();

        $this->assertSame(1, ActivityEntry::count());
    }

    public function test_the_default_retention_is_180_days(): void
    {
        $this->assertSame(180, config('kintai.activity.retention_days'));
    }

    public function test_the_purge_runs_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'kintai:activity:purge')));
    }

    public function test_an_unknown_action_is_shown_by_its_raw_key(): void
    {
        $registry = app(ActivityActionRegistry::class);

        $this->assertFalse($registry->has('bundle.custom'));
        $this->assertSame('bundle.custom', $registry->label('bundle.custom'));
    }

    public function test_bundles_can_register_their_actions_but_not_twice(): void
    {
        $registry = app(ActivityActionRegistry::class);
        $registry->register('timeoff.approved', 'Time off approved', 'Time off');

        $this->assertSame('Time off approved', $registry->label('timeoff.approved'));
        $this->assertSame('Time off approved', $registry->grouped()['Time off']['timeoff.approved']);

        $this->expectException(InvalidArgumentException::class);
        $registry->register('timeoff.approved', 'Again', 'Time off');
    }

    public function test_every_core_action_label_and_category_has_a_french_translation(): void
    {
        $translations = json_decode((string) file_get_contents(base_path('lang/fr.json')), true, 512, JSON_THROW_ON_ERROR);
        $registry = app(ActivityActionRegistry::class);

        $this->assertNotEmpty($registry->all());

        $missing = [];
        foreach ($registry->all() as $key => $action) {
            foreach ([$action['label'], $action['category']] as $text) {
                if (! array_key_exists($text, $translations)) {
                    $missing[$key] = $text;
                }
            }
        }

        $this->assertSame([], $missing, 'Registry labels missing from lang/fr.json');
    }

    public function test_every_property_label_has_a_french_translation(): void
    {
        $translations = json_decode((string) file_get_contents(base_path('lang/fr.json')), true, 512, JSON_THROW_ON_ERROR);

        $missing = array_values(array_filter(
            [...array_values(ActivityDetails::PROPERTY_LABELS), 'Added', 'Removed'],
            fn (string $label): bool => ! array_key_exists($label, $translations),
        ));

        $this->assertSame([], $missing);
    }

    public function test_labels_are_translated_at_display_time(): void
    {
        app()->setLocale('fr');

        $this->assertSame('Magasin créé', app(ActivityActionRegistry::class)->label('store.created'));
    }
}
