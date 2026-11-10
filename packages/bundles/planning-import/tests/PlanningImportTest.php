<?php

namespace Kintai\Bundles\PlanningImport\Tests;

use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\PlanningImport\Detection\GridSettings;
use Kintai\Bundles\PlanningImport\ImportAccess;
use Kintai\Bundles\PlanningImport\ImportApplier;
use Kintai\Bundles\PlanningImport\Models\ImportAlias;
use Kintai\Bundles\PlanningImport\Models\StoreImportSetting;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once __DIR__.'/PlanningSheet.php';

/**
 * Import de planning : accès (droit `shifts.import` du Core, portée magasin, bundle désactivé), aperçu avec rattachement
 * des noms et alias, confirmation qui crée les shifts par les actions du Core, réglages par magasin, création d'un employé
 * inconnu, nettoyage à l'anonymisation. Les fichiers sont synthétiques (voir `PlanningSheet`).
 */
class PlanningImportTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private User $manager;

    private User $tanaka;

    private User $sato;

    private PlanningSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Zulu']);
        $this->manager = $this->managerOf($this->a);
        $this->tanaka = $this->employeeOf($this->a, ['name' => 'Tanaka Hanako', 'last_name' => 'Tanaka', 'first_name' => 'Hanako']);
        $this->sato = $this->employeeOf($this->a, ['name' => 'Sato Ichiro', 'last_name' => 'Sato', 'first_name' => 'Ichiro']);
        $this->sheet = new PlanningSheet;
    }

    protected function tearDown(): void
    {
        $this->sheet->cleanup();

        parent::tearDown();
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('2026年11月.xlsx', $this->sheet->contents());
    }

    /**
     * Aperçu du fichier courant par `$user` dans le magasin `$store`.
     */
    private function preview(?User $user = null, ?Store $store = null): Testable
    {
        return Livewire::actingAs($user ?? $this->manager)->test('planning-import.index')
            ->set('store', (string) ($store ?? $this->a)->id)
            ->set('file', $this->upload())
            ->call('preview');
    }

    /**
     * Tanaka (rose, 06:00-10:00) et Sato (bleu, 09:00-13:00) le 3 novembre.
     */
    private function twoShifts(): void
    {
        $this->sheet->date(1, '2026-11-03')
            ->colored(1, 1, 4, 11, 'FFAACC', 'Tanaka Hanako')
            ->colored(1, 2, 10, 17, '00AAFF', 'Sato Ichiro');
    }

    private function shiftsOf(User $user, ?Store $store = null): Collection
    {
        return Shift::query()->where('store_id', ($store ?? $this->a)->id)->where('user_id', $user->id)->orderBy('starts_at')->get();
    }

    private function times(Shift $shift): string
    {
        return $shift->shift_date->toDateString().' '.$shift->localStartsAt->format('H:i').'-'.$shift->localEndsAt->format('H:i');
    }

    // ---------------------------------------------------------------- accès

    public function test_the_tables_exist_even_when_the_bundle_is_disabled(): void
    {
        $this->assertTrue(Schema::hasTable('store_import_settings'));
        $this->assertTrue(Schema::hasTable('import_aliases'));
        $this->assertTrue(Schema::hasColumn('import_aliases', 'staff_name'));
    }

    public function test_a_disabled_bundle_is_absent_everywhere(): void
    {
        $this->assertFalse(app(NavigationRegistry::class)->has('planning-import'));

        $this->actingAs($this->owner())->get('/planning-import')->assertNotFound();
        $this->actingAs($this->owner())->get('/planning-import/settings')->assertNotFound();
    }

    public function test_the_menu_entry_exists_once_enabled(): void
    {
        $this->enableBundle('planning-import');

        $this->assertTrue(app(NavigationRegistry::class)->has('planning-import'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->enableBundle('planning-import');

        $this->get('/planning-import')->assertRedirect(route('login'));
    }

    public function test_the_screens_need_the_core_import_permission(): void
    {
        $this->enableBundle('planning-import');

        foreach (['/planning-import', '/planning-import/settings'] as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk();
            $this->actingAs($this->owner())->get($url)->assertOk();
            $this->actingAs($this->tanaka)->get($url)->assertForbidden();

            $viewer = User::factory()->create();
            $viewer->assignRole($this->customRole(['shifts.view', 'shifts.create']), $this->a);
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
    }

    public function test_the_import_is_limited_to_the_stores_where_the_user_holds_the_permission(): void
    {
        $this->enableBundle('planning-import');
        $access = app(ImportAccess::class);

        $this->assertSame([$this->a->id => 'Alpha'], $access->stores($this->manager));
        $this->assertSame([$this->a->id => 'Alpha', $this->b->id => 'Zulu'], $access->stores($this->owner()));
        $this->assertSame([], $access->stores($this->tanaka));

        $this->a->update(['is_active' => false]);
        $this->assertSame([], $access->stores($this->manager));
    }

    public function test_previewing_or_importing_for_another_store_is_refused(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();

        $this->preview($this->manager, $this->b)->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(ImportApplier::class)->apply($this->manager, $this->b->id, []);
    }

    // ---------------------------------------------------------------- aperçu

    public function test_the_preview_matches_names_to_employees_and_remembers_aliases(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->date(1, '2026-11-03')
            ->colored(1, 1, 4, 11, 'FFAACC', 'Tanaka Hanako')
            ->colored(1, 2, 4, 11, '00AAFF', 'Ichi')
            ->colored(1, 3, 4, 11, '00FFAA', 'Stranger');
        ImportAlias::create(['store_id' => $this->a->id, 'staff_name' => 'ichi', 'user_id' => $this->sato->id]);

        $page = $this->preview()->assertHasNoErrors();
        $rows = $page->get('rows');

        $this->assertSame(['Tanaka Hanako', 'Ichi', 'Stranger'], array_column($rows, 'staff_name'));
        $this->assertSame([$this->tanaka->id, $this->sato->id, 0], array_column($rows, 'user_id'));
        $this->assertSame([false, false, true], array_column($rows, 'skip'));
        $this->assertSame('2026-11-03', $rows[0]['date']);
        $this->assertSame(['06:00', '10:00'], [$rows[0]['start_time'], $rows[0]['end_time']]);
        $this->assertSame(0, Shift::count());
    }

    public function test_a_name_that_matches_several_employees_is_left_to_the_user(): void
    {
        $this->enableBundle('planning-import');
        $this->employeeOf($this->a, ['name' => 'Tanaka Hanako', 'last_name' => 'Tanaka', 'first_name' => 'Hanako']);
        $this->twoShifts();

        $this->assertSame([0, $this->sato->id], array_column($this->preview()->get('rows'), 'user_id'));
    }

    public function test_aliases_of_inactive_members_are_dropped_and_ambiguous_ones_ignored(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        $outsider = $this->employeeOf($this->b);
        ImportAlias::create(['store_id' => $this->a->id, 'staff_name' => 'gone', 'user_id' => $outsider->id]);

        $this->preview();

        $this->assertSame(0, ImportAlias::where('staff_name', 'gone')->count());
    }

    public function test_names_only_match_employees_of_the_store(): void
    {
        $this->enableBundle('planning-import');
        $this->employeeOf($this->b, ['name' => 'Kato Jiro', 'last_name' => 'Kato', 'first_name' => 'Jiro']);
        $this->sheet->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAACC', 'Kato Jiro');

        $this->assertSame([0], array_column($this->preview()->get('rows'), 'user_id'));
    }

    public function test_contiguous_ranges_of_the_same_name_are_merged_in_the_preview(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->date(1, '2026-11-03')
            ->colored(1, 1, 4, 9, 'FFAACC', 'Tanaka Hanako')
            ->colored(1, 2, 10, 15, '00AAFF', 'Tanaka Hanako');

        $rows = $this->preview()->get('rows');

        $this->assertCount(1, $rows);
        $this->assertSame(['06:00', '12:00', 6.0], [$rows[0]['start_time'], $rows[0]['end_time'], $rows[0]['hours']]);
    }

    public function test_the_preview_flags_existing_and_overlapping_shifts(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        Shift::fromLocalTimes('2026-11-03', '06:00', '10:00', ['store_id' => $this->a->id, 'user_id' => $this->tanaka->id])->save();
        Shift::fromLocalTimes('2026-11-03', '12:00', '16:00', ['store_id' => $this->a->id, 'user_id' => $this->sato->id])->save();

        $rows = $this->preview()->get('rows');

        $this->assertSame([true, false], array_column($rows, 'exists'));
        $this->assertSame([false, true], array_column($rows, 'overlap'));
        $this->assertSame([true, false], array_column($rows, 'skip'));
    }

    public function test_an_unreadable_or_empty_file_gives_an_error_and_no_preview(): void
    {
        $this->enableBundle('planning-import');

        $text = Livewire::actingAs($this->manager)->test('planning-import.index')
            ->set('file', UploadedFile::fake()->createWithContent('planning.xlsx', 'not a spreadsheet'))
            ->call('preview');
        $text->assertHasErrors(['file']);
        $this->assertSame([], $text->get('rows'));

        // Fichier valide mais sans aucun shift.
        $this->sheet->date(1, '2026-11-03');
        $this->preview()->assertHasErrors(['file']);
    }

    // ---------------------------------------------------------------- confirmation

    public function test_confirming_creates_the_shifts_with_the_core_actions_and_remembers_the_names(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        StoreImportSetting::create(['store_id' => $this->a->id, 'auto_pause_after_minutes' => 180, 'auto_pause_minutes' => 20]);

        $this->preview()->call('confirm')->assertHasNoErrors()->assertSet('rows', [])
            ->assertSet('result', ['created' => 2, 'replaced' => 0, 'skipped' => 0, 'removed' => 0]);

        $tanaka = $this->shiftsOf($this->tanaka);
        $this->assertCount(1, $tanaka);
        $this->assertSame('2026-11-03 06:00-10:00', $this->times($tanaka[0]));
        $this->assertSame(20, $tanaka[0]->pause_minutes);
        $this->assertSame($this->manager->id, $tanaka[0]->created_by);
        $this->assertSame('2026-11-03 09:00-13:00', $this->times($this->shiftsOf($this->sato)[0]));

        $this->assertEqualsCanonicalizing(['tanaka hanako', 'sato ichiro'], ImportAlias::where('store_id', $this->a->id)->pluck('staff_name')->all());

        $entry = ActivityEntry::where('action', 'planning_import.imported')->sole();
        $this->assertSame($this->a->id, $entry->store_id);
        $this->assertSame(2, $entry->properties['created']);
        $this->assertStringNotContainsString('Tanaka', (string) json_encode($entry->properties));
    }

    public function test_a_shift_without_pause_setting_has_no_break(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();

        $this->preview()->call('confirm');

        $this->assertSame(0, $this->shiftsOf($this->tanaka)[0]->pause_minutes);
    }

    public function test_the_alias_chosen_in_the_preview_is_used_by_the_next_import(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAACC', 'Ichi');

        $page = $this->preview();
        $this->assertSame([0], array_column($page->get('rows'), 'user_id'));
        $page->set('rows.0.user_id', (string) $this->sato->id)->set('rows.0.skip', false)->call('confirm');

        $this->assertCount(1, $this->shiftsOf($this->sato));
        $this->assertSame($this->sato->id, ImportAlias::where('staff_name', 'ichi')->sole()->user_id);

        $this->assertSame([$this->sato->id], array_column($this->preview()->get('rows'), 'user_id'));
    }

    public function test_an_identical_existing_shift_is_skipped_and_an_overlapping_one_is_replaced(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        $exact = Shift::fromLocalTimes('2026-11-03', '06:00', '10:00', ['store_id' => $this->a->id, 'user_id' => $this->tanaka->id]);
        $exact->save();
        $overlap = Shift::fromLocalTimes('2026-11-03', '12:00', '16:00', ['store_id' => $this->a->id, 'user_id' => $this->sato->id]);
        $overlap->save();

        $page = $this->preview();
        // Le shift identique n'est pas coché ; on force aussi la ligne pour vérifier que le Core ne le duplique pas.
        $page->set('rows.0.skip', false)->call('confirm')
            ->assertSet('result', ['created' => 0, 'replaced' => 1, 'skipped' => 1, 'removed' => 0]);

        $this->assertCount(1, $this->shiftsOf($this->tanaka));
        $this->assertSame($exact->id, $this->shiftsOf($this->tanaka)[0]->id);
        $this->assertTrue($overlap->fresh()->trashed());
        $this->assertSame(['2026-11-03 09:00-13:00'], $this->shiftsOf($this->sato)->map(fn (Shift $s): string => $this->times($s))->all());
    }

    public function test_the_replaced_shift_goes_to_the_trash_and_points_to_its_replacement(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        $old = Shift::fromLocalTimes('2026-11-03', '12:00', '16:00', ['store_id' => $this->a->id, 'user_id' => $this->sato->id]);
        $old->save();

        $this->preview()->call('confirm');

        $old->refresh();
        $this->assertNotNull($old->deleted_at);
        $new = $this->shiftsOf($this->sato);
        $this->assertCount(1, $new);
        $this->assertSame($new[0]->id, $old->replaced_by_id);
        $this->assertSame('2026-11-03 09:00-13:00', $this->times($new[0]));
    }

    public function test_shifts_that_the_file_no_longer_contains_are_removed_inside_its_period_only(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAACC', 'Tanaka Hanako');
        $this->sheet->date(3, '2026-11-10')->colored(3, 1, 4, 11, 'FFAACC', 'Tanaka Hanako');
        $inside = Shift::fromLocalTimes('2026-11-05', '08:00', '12:00', ['store_id' => $this->a->id, 'user_id' => $this->tanaka->id]);
        $inside->save();
        $before = Shift::fromLocalTimes('2026-11-01', '08:00', '12:00', ['store_id' => $this->a->id, 'user_id' => $this->tanaka->id]);
        $before->save();
        $other = Shift::fromLocalTimes('2026-11-05', '08:00', '12:00', ['store_id' => $this->a->id, 'user_id' => $this->sato->id]);
        $other->save();
        $elsewhere = Shift::fromLocalTimes('2026-11-05', '08:00', '12:00', ['store_id' => $this->b->id, 'user_id' => $this->tanaka->id]);
        $elsewhere->save();

        $this->preview()->call('confirm')->assertSet('result', ['created' => 2, 'replaced' => 0, 'skipped' => 0, 'removed' => 1]);

        $this->assertTrue($inside->fresh()->trashed());
        foreach ([$before, $other, $elsewhere] as $kept) {
            $this->assertFalse($kept->fresh()->trashed());
        }
    }

    public function test_nothing_is_removed_without_the_delete_permission(): void
    {
        $this->enableBundle('planning-import');
        $importer = User::factory()->create();
        $importer->assignRole($this->customRole(['shifts.import', 'shifts.create', 'shifts.update']), $this->a);
        $this->sheet->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAACC', 'Tanaka Hanako');
        $this->sheet->date(3, '2026-11-10')->colored(3, 1, 4, 11, 'FFAACC', 'Tanaka Hanako');
        $inside = Shift::fromLocalTimes('2026-11-05', '08:00', '12:00', ['store_id' => $this->a->id, 'user_id' => $this->tanaka->id]);
        $inside->save();

        $this->preview($importer)->call('confirm')->assertSet('result.removed', 0);

        $this->assertFalse($inside->fresh()->trashed());
    }

    public function test_the_importer_needs_the_core_right_to_create_shifts(): void
    {
        $this->enableBundle('planning-import');
        $importer = User::factory()->create();
        $importer->assignRole($this->customRole(['shifts.import']), $this->a);
        $this->twoShifts();

        $this->preview($importer)->call('confirm')->assertForbidden();

        $this->assertSame(0, Shift::count());
    }

    public function test_the_preview_is_checked_again_on_confirmation(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        $outsider = $this->employeeOf($this->b);

        $page = $this->preview();
        $page->set('rows.0.user_id', (string) $outsider->id)      // employé d'un autre magasin
            ->set('rows.1.date', 'not a date')                     // date invalide
            ->call('confirm')
            ->assertSet('result', ['created' => 0, 'replaced' => 0, 'skipped' => 2, 'removed' => 0]);

        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ImportAlias::count());
    }

    public function test_a_row_left_unchecked_is_not_imported(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();

        $this->preview()->set('rows.1.skip', true)->call('confirm')->assertSet('result.created', 1)->assertSet('result.skipped', 1);

        $this->assertCount(0, $this->shiftsOf($this->sato));
    }

    public function test_confirming_without_a_preview_or_after_changing_store_is_refused(): void
    {
        $this->enableBundle('planning-import');
        $owner = $this->owner();
        $this->twoShifts();

        Livewire::actingAs($owner)->test('planning-import.index')->call('confirm')->assertForbidden();

        $page = $this->preview($owner);
        $this->assertNotEmpty($page->get('rows'));
        $page->set('store', (string) $this->b->id);
        $this->assertSame([], $page->get('rows'));
        $page->call('confirm')->assertForbidden();
    }

    // ---------------------------------------------------------------- employé inconnu

    public function test_an_unknown_name_can_become_a_new_employee_of_the_store(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAACC', 'Stranger')->colored(1, 2, 4, 11, 'FFAACC', 'Stranger');
        $this->sheet->date(2, '2026-11-04')->colored(2, 1, 4, 11, '00AAFF', 'Stranger');

        $page = $this->preview();
        $page->call('startCreate', 'Stranger')
            ->set('newLastName', 'Stranger')->set('newFirstName', 'Anna')->set('newFuriganaLastName', 'ストレンジャー')->set('newFuriganaFirstName', 'アンナ')
            ->call('createEmployee')->assertHasNoErrors();

        $employee = User::query()->where('last_name', 'Stranger')->sole();
        $this->assertTrue($this->a->members()->where('users.id', $employee->id)->wherePivot('is_active', true)->exists());
        $this->assertSame([$employee->id], array_unique(array_column($page->get('rows'), 'user_id')));
        $this->assertContains(false, array_column($page->get('rows'), 'skip'));

        $page->call('confirm');
        $this->assertCount(2, $this->shiftsOf($employee));
    }

    public function test_creating_an_employee_needs_valid_data_and_the_core_right(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAACC', 'Stranger');

        $this->preview()->call('startCreate', 'Stranger')->set('newFirstName', '')->call('createEmployee')->assertHasErrors(['first_name']);
        $this->assertSame(0, User::query()->where('last_name', 'Stranger')->count());

        $importer = User::factory()->create();
        $importer->assignRole($this->customRole(['shifts.import', 'shifts.create']), $this->a);
        $this->preview($importer)->call('startCreate', 'Stranger')
            ->set('newFirstName', 'Anna')->set('newFuriganaLastName', 'ス')->set('newFuriganaFirstName', 'ア')
            ->call('createEmployee')->assertHasErrors(['newLastName']);
        $this->assertSame(0, User::query()->where('last_name', 'Stranger')->count());
    }

    // ---------------------------------------------------------------- réglages

    public function test_the_grid_of_a_store_changes_what_is_read_for_that_store_only(): void
    {
        $this->enableBundle('planning-import');
        $this->twoShifts();
        $bManager = $this->managerOf($this->b);
        $bTanaka = $this->employeeOf($this->b, ['name' => 'Tanaka Hanako', 'last_name' => 'Tanaka', 'first_name' => 'Hanako']);
        StoreImportSetting::create(['store_id' => $this->a->id, 'base_hour' => 8]);

        $this->assertSame('08:00', $this->preview()->get('rows')[0]['start_time']);
        $this->assertSame('06:00', $this->preview($bManager, $this->b)->get('rows')[0]['start_time']);
        $this->assertNotSame($this->tanaka->id, $bTanaka->id);

        $this->assertSame(8, GridSettings::forStore($this->a->id)->baseHour);
        $this->assertSame(6, GridSettings::forStore($this->b->id)->baseHour);
    }

    public function test_the_sheet_pattern_of_a_store_selects_the_sheets(): void
    {
        $this->enableBundle('planning-import');
        $this->sheet->sheet->setTitle('Notes');
        $this->twoShifts();
        $november = $this->sheet->addSheet('Nov');
        $this->sheet->date(1, '2026-11-04', $november)->colored(1, 1, 4, 11, 'FFAACC', 'Tanaka Hanako', $november);
        StoreImportSetting::create(['store_id' => $this->a->id, 'sheet_filter_pattern' => '^Nov$']);

        $rows = $this->preview()->get('rows');

        $this->assertSame(['2026-11-04'], array_column($rows, 'date'));
    }

    public function test_the_settings_are_saved_per_store_and_logged_without_values(): void
    {
        $this->enableBundle('planning-import');

        Livewire::actingAs($this->manager)->test('planning-import.settings')
            ->assertSet('colStart', 4)->assertSet('sheetPattern', '^\d{4}$')
            ->set('colStart', 2)->set('colEnd', 30)->set('baseHour', 7)->set('minutesPerCol', 15)->set('blockSize', 10)->set('shiftRows', 8)
            ->set('sheetPattern', '^Nov')->set('autoPauseAfter', 360)->set('autoPause', 45)
            ->call('save')->assertHasNoErrors();

        $grid = GridSettings::forStore($this->a->id);
        $this->assertSame([2, 30, 7, 15, 10, 8, '^Nov', 360, 45], [
            $grid->colStart, $grid->colEnd, $grid->baseHour, $grid->minutesPerCol, $grid->blockSize, $grid->shiftRows,
            $grid->sheetPattern, $grid->autoPauseAfterMinutes, $grid->autoPauseMinutes,
        ]);
        $this->assertSame(0, StoreImportSetting::where('store_id', $this->b->id)->count());
        $this->assertNotNull(ActivityEntry::where('action', 'planning_import.settings_updated')->where('store_id', $this->a->id)->first());
    }

    public function test_invalid_settings_are_refused(): void
    {
        $this->enableBundle('planning-import');
        $page = Livewire::actingAs($this->manager)->test('planning-import.settings');

        $page->set('sheetPattern', '(')->call('save')->assertHasErrors(['sheetPattern']);
        $page->set('sheetPattern', '^\d{4}$')->set('colStart', 10)->set('colEnd', 5)->call('save')->assertHasErrors(['colEnd']);
        $page->set('colEnd', 52)->set('baseHour', 24)->call('save')->assertHasErrors(['baseHour']);
        $page->set('baseHour', 6)->set('minutesPerCol', 0)->call('save')->assertHasErrors(['minutesPerCol']);

        $this->assertSame(0, StoreImportSetting::count());
    }

    public function test_the_settings_of_another_store_cannot_be_changed(): void
    {
        $this->enableBundle('planning-import');

        Livewire::actingAs($this->manager)->test('planning-import.settings')->set('store', (string) $this->b->id)->assertForbidden();

        $this->assertSame(0, StoreImportSetting::count());
    }

    // ---------------------------------------------------------------- données personnelles

    public function test_aliases_of_an_anonymized_employee_are_deleted(): void
    {
        $this->enableBundle('planning-import');
        ImportAlias::create(['store_id' => $this->a->id, 'staff_name' => 'ichi', 'user_id' => $this->sato->id]);
        ImportAlias::create(['store_id' => $this->a->id, 'staff_name' => 'hana', 'user_id' => $this->tanaka->id]);

        UserAnonymized::dispatch($this->sato->id);

        $this->assertSame(['hana'], ImportAlias::pluck('staff_name')->all());
    }
}
