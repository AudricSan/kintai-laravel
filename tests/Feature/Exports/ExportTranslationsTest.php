<?php

namespace Tests\Feature\Exports;

use App\Activity\ActivityActionRegistry;
use App\Activity\ActivityDetails;
use App\Exports\ExportDatasetRegistry;
use App\Models\Store;
use App\Models\User;
use App\Profile\ProfileSectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les libellés passés par variable (jeux de données, colonnes, actions du journal, menu, sections du profil) échappent au
 * test des chaînes littérales : ils ont leur propre garde-fou.
 */
class ExportTranslationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function french(): array
    {
        return json_decode((string) file_get_contents(lang_path('fr.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_dataset_and_column_labels_are_translated(): void
    {
        $french = $this->french();

        foreach (app(ExportDatasetRegistry::class)->all() as $dataset) {
            $this->assertArrayHasKey($dataset->label(), $french, "Missing translation of dataset {$dataset->key()}");

            foreach ($dataset->columns() as $column) {
                $this->assertArrayHasKey($column->label, $french, "Missing translation of column {$column->label}");
            }
        }
    }

    public function test_export_and_calendar_activity_labels_and_properties_are_translated(): void
    {
        $french = $this->french();
        $registry = app(ActivityActionRegistry::class);

        foreach (['export.downloaded', 'export.schedule', 'export.personal_data', 'ical.token_regenerated', 'ical.token_deleted'] as $key) {
            $this->assertTrue($registry->has($key), "Action {$key} is not registered");
            $this->assertArrayHasKey($registry->all()[$key]['label'], $french);
            $this->assertArrayHasKey($registry->all()[$key]['category'], $french);
        }
        foreach (['dataset', 'format', 'stores', 'from', 'to', 'count'] as $property) {
            $this->assertArrayHasKey(ActivityDetails::PROPERTY_LABELS[$property], $french);
        }
    }

    public function test_menu_and_settings_labels_are_translated(): void
    {
        $french = $this->french();

        $this->assertArrayHasKey('Exports', $french);
        $user = User::factory()->create();
        $user->stores()->attach(Store::factory()->create());

        foreach (app(ProfileSectionRegistry::class)->sections($user) as $section) {
            $this->assertArrayHasKey($section->label, $french, "Missing translation of section {$section->key}");
        }
        $this->assertContains('calendar', array_map(fn ($s) => $s->key, app(ProfileSectionRegistry::class)->sections($user)));
        $this->assertContains('data', array_map(fn ($s) => $s->key, app(ProfileSectionRegistry::class)->sections($user)));
    }

    public function test_the_exports_page_and_the_settings_pages_render_in_french(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);
        $user->stores()->attach(Store::factory()->create());

        $this->actingAs($user)->get(route('exports.index'))->assertSee('Télécharger');
        $this->get(route('calendar.edit'))->assertSee('Exporter mon planning');
        $this->get(route('data.edit'))->assertSee('Exporter mes données');
    }
}
