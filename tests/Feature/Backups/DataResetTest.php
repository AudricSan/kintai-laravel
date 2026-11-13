<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\ResetApplicationData;
use App\Actions\Backups\RestoreBackup;
use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Backups\DataResetPlanner;
use App\Models\ActivityEntry;
use App\Models\Availability;
use App\Models\DashboardLayout;
use App\Models\IcalToken;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNavPref;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesBackupDirectory;
use Tests\TestCase;

class DataResetTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesBackupDirectory;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupDirectory();
        $this->seedRoles();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
    }

    /** Magasin, membre, type de shift avec taux, shift, disponibilité, préférences : de quoi vider. */
    private function populate(): array
    {
        $store = Store::factory()->create();
        $employee = $this->employeeOf($store);
        $type = ShiftType::factory()->create();
        $store->shiftTypes()->attach($type);
        DB::table('shift_type_user')->insert(['user_id' => $employee->id, 'shift_type_id' => $type->id, 'hourly_rate' => 12.5, 'created_at' => now(), 'updated_at' => now()]);
        $shift = Shift::factory()->create(['store_id' => $store->id, 'user_id' => $employee->id, 'shift_type_id' => $type->id]);
        Availability::factory()->create(['user_id' => $employee->id, 'store_id' => $store->id]);
        UserNavPref::factory()->create(['user_id' => $employee->id]);
        DashboardLayout::factory()->create(['user_id' => $employee->id]);
        IcalToken::factory()->create(['user_id' => $employee->id, 'store_id' => $store->id]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'user', 'notifiable_id' => $employee->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return compact('store', 'employee', 'type', 'shift');
    }

    private function reset(array $keep = [], string $confirmation = 'RESET', string $password = 'password', ?User $actor = null): array
    {
        return $this->app->make(ResetApplicationData::class)->handle($actor ?? $this->owner, $keep, $confirmation, $password);
    }

    public function test_by_default_everything_that_is_not_preserved_is_erased(): void
    {
        ['employee' => $employee, 'store' => $store] = $this->populate();

        $result = $this->reset();

        foreach (['shifts', 'availabilities', 'stores', 'store_user', 'store_features', 'shift_types', 'shift_type_store', 'shift_type_user', 'dashboard_layouts', 'user_nav_prefs', 'ical_tokens', 'notifications'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }

        // Comptes, rôles, journal : intacts.
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
        $this->assertGreaterThan(0, Role::query()->count());
        $this->assertDatabaseHas('role_user', ['user_id' => $this->owner->id, 'store_id' => null]);
        $this->assertDatabaseMissing('role_user', ['store_id' => $store->id]); // les rattachements propres à un magasin disparaissent avec lui
        $this->assertGreaterThan(0, $result['tables']['shifts']);
    }

    public function test_the_always_preserved_tables_are_never_touched(): void
    {
        $this->populate();
        DB::table('app_settings')->insert(['key' => 'organization.subtitle', 'value' => json_encode('Kept')]);
        $this->owner->createToken('api');
        $counts = [];
        $preserved = array_values(array_intersect(DataResetPlanner::ALWAYS_PRESERVED, array_column(Schema::getTables(), 'name')));

        foreach ($preserved as $table) {
            $counts[$table] = $this->countKept($table);
        }

        $this->reset([]);

        foreach ($preserved as $table) {
            // Le journal gagne l'entrée de la réinitialisation et celle de la sauvegarde.
            if ($table === 'activity_entries') {
                $this->assertGreaterThan($counts[$table], DB::table($table)->count());

                continue;
            }

            $this->assertSame($counts[$table], $this->countKept($table), "{$table} must be preserved");
        }

        $this->assertSame('Kept', json_decode((string) DB::table('app_settings')->where('key', 'organization.subtitle')->value('value')));
        $this->assertGreaterThan(0, DB::table('personal_access_tokens')->count());
    }

    /** Les rattachements de rôle propres à un magasin disparaissent avec le magasin (cascade) : seuls les globaux sont comptés. */
    private function countKept(string $table): int
    {
        return $table === 'role_user' ? DB::table($table)->whereNull('store_id')->count() : DB::table($table)->count();
    }

    public function test_the_always_preserved_list_only_names_real_tables(): void
    {
        $tables = array_column(Schema::getTables(), 'name');

        foreach (DataResetPlanner::ALWAYS_PRESERVED as $table) {
            $this->assertContains($table, $tables, "{$table} is not a table of the schema");
        }

        foreach (DataResetPlanner::OPTIONAL as $category => $names) {
            foreach ($names as $table) {
                $this->assertContains($table, $tables, "{$table} ({$category}) is not a table of the schema");
            }
        }
    }

    public function test_every_table_is_either_preserved_optional_or_wiped(): void
    {
        $planner = new DataResetPlanner;
        $wiped = $planner->tablesToWipe([]);
        $all = array_column(Schema::getTables(), 'name');
        $optional = array_merge(...array_values(DataResetPlanner::OPTIONAL));

        $this->assertEqualsCanonicalizing($all, array_unique([...$wiped, ...DataResetPlanner::ALWAYS_PRESERVED]));
        $this->assertSame([], array_intersect($wiped, DataResetPlanner::ALWAYS_PRESERVED));
        $this->assertSame([], array_diff($optional, $wiped), 'unchecked categories are wiped');
    }

    public function test_the_keep_boxes_are_respected(): void
    {
        ['employee' => $employee, 'store' => $store, 'type' => $type] = $this->populate();

        $this->reset(['stores']);
        $this->assertSame(1, Store::query()->count());
        $this->assertSame(1, DB::table('store_user')->where('user_id', $employee->id)->count());
        $this->assertSame(0, ShiftType::query()->count());
        $this->assertSame(0, DB::table('user_nav_prefs')->count());
        $this->assertSame(0, Shift::query()->count());
        $this->assertDatabaseHas('role_user', ['user_id' => $employee->id, 'store_id' => $store->id]);
    }

    public function test_shift_types_and_rates_can_be_kept(): void
    {
        ['employee' => $employee, 'type' => $type] = $this->populate();

        $this->reset(['shift_types']);

        $this->assertDatabaseHas('shift_types', ['id' => $type->id]);
        $this->assertDatabaseHas('shift_type_user', ['user_id' => $employee->id, 'shift_type_id' => $type->id, 'hourly_rate' => 12.5]);
        $this->assertSame(0, Store::query()->count());
        $this->assertSame(0, DB::table('shift_type_store')->count(), 'links to the erased stores are gone');
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_interface_preferences_can_be_kept(): void
    {
        $this->populate();

        $this->reset(['ui_prefs']);

        $this->assertSame(1, UserNavPref::query()->count());
        $this->assertSame(1, DashboardLayout::query()->count());
        $this->assertSame(0, Store::query()->count());
    }

    public function test_keeping_everything_only_erases_the_planning_data(): void
    {
        $this->populate();

        $result = $this->reset(['stores', 'shift_types', 'ui_prefs']);

        $this->assertSame(1, Store::query()->count());
        $this->assertSame(1, ShiftType::query()->count());
        $this->assertSame(0, Shift::query()->count());
        $this->assertSame(0, DB::table('availabilities')->count());
        $this->assertArrayNotHasKey('stores', $result['tables']);
    }

    public function test_an_unknown_category_is_ignored(): void
    {
        $this->populate();

        $this->reset(['users', 'roles', 'nonsense']);

        $this->assertGreaterThan(0, User::query()->count());
        $this->assertSame(0, Store::query()->count());
    }

    public function test_it_requires_the_confirmation_text_and_the_password(): void
    {
        $this->populate();

        foreach (['', 'reset', 'RESET ', 'yes'] as $text) {
            try {
                $this->reset([], $text);
                $this->fail('A wrong confirmation text must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('confirmation', $e->errors());
            }
        }

        foreach (['', 'wrong'] as $password) {
            try {
                $this->reset([], 'RESET', $password);
                $this->fail('A wrong password must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }

        $this->assertSame(1, Store::query()->count());
        $this->assertSame([], $this->backups()->all(), 'no backup is made for a refused reset');
    }

    public function test_only_the_owner_can_reset(): void
    {
        $store = $this->populate()['store'];

        $this->expectException(AuthorizationException::class);
        $this->reset([], 'RESET', 'password', $this->managerOf($store));
    }

    public function test_a_backup_is_made_just_before_and_is_restorable(): void
    {
        ['store' => $store] = $this->populate();

        $result = $this->reset();

        $backup = $this->backups()->find($result['backup']);
        $this->assertNotNull($backup);
        $this->assertSame('reset', $backup->trigger);

        // La sauvegarde contient les données d'avant.
        $this->assertSame(0, Store::query()->count());
        $this->app->make(RestoreBackup::class)->handle($this->owner, $backup->id, 'password');
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
        $this->assertSame(1, Shift::query()->count());
    }

    public function test_nothing_is_erased_when_the_backup_fails(): void
    {
        $this->populate();
        $this->app->bind(BackupManager::class, fn () => new class extends BackupManager
        {
            public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
            {
                throw new BackupException('The backups folder cannot be written.');
            }
        });

        try {
            $this->reset();
            $this->fail('The reset must stop when the backup fails.');
        } catch (BackupException) {
        }

        $this->assertSame(1, Store::query()->count());
        $this->assertSame(1, Shift::query()->count());
    }

    public function test_the_reset_is_logged_and_the_log_is_kept(): void
    {
        $this->populate();
        ActivityEntry::query()->create(['actor_id' => $this->owner->id, 'action' => 'shift.created', 'created_at' => now()]);
        $before = ActivityEntry::query()->count();

        $result = $this->reset(['ui_prefs']);

        $entry = ActivityEntry::query()->where('action', 'app.data_reset')->firstOrFail();
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame(['ui_prefs'], $entry->properties['kept']);
        $this->assertSame($result['backup'], $entry->properties['backup']);
        $this->assertContains('shifts', $entry->properties['tables']);
        $this->assertNotContains('user_nav_prefs', $entry->properties['tables']);
        $this->assertDatabaseHas('activity_entries', ['action' => 'shift.created']);
        $this->assertGreaterThanOrEqual($before + 1, ActivityEntry::query()->count());
    }

    public function test_the_session_of_the_owner_survives_the_reset(): void
    {
        $this->populate();

        $this->reset();

        $this->assertAuthenticated();
        $this->get(route('data-reset.index'))->assertOk();
    }

    public function test_the_screen_lists_what_will_be_erased_and_follows_the_boxes(): void
    {
        $this->populate();

        $screen = Livewire::test('pages::admin.data-reset')
            ->assertSee('What will be erased')
            ->assertSee('Shifts')
            ->assertSee('Stores')
            ->assertSee('Employee hourly rates');

        $screen->set('keep_stores', true)->assertDontSee('Store members');
        $screen->set('keep_shift_types', true)->assertDontSee('Employee hourly rates');
        $screen->set('keep_ui_prefs', true)->assertDontSee('Menu preferences');
    }

    public function test_the_screen_resets_after_confirmation(): void
    {
        $this->populate();

        $screen = Livewire::test('pages::admin.data-reset')
            ->call('confirmReset')
            ->set('confirmation', 'nope')
            ->set('password', 'password')
            ->call('executeReset')
            ->assertHasErrors('confirmation');
        $this->assertSame(1, Store::query()->count());

        $screen->set('confirmation', 'RESET')->set('password', 'bad')->call('executeReset')->assertHasErrors('password');
        $this->assertSame(1, Store::query()->count());

        $screen->set('password', 'password')->call('executeReset')->assertHasNoErrors()->assertSee('The data was reset.');

        $this->assertSame(0, Store::query()->count());
        $this->assertCount(1, $this->backups()->all());
    }

    public function test_only_the_owner_reaches_the_screen(): void
    {
        $this->get(route('data-reset.index'))->assertOk()->assertSee('Reset data');

        $this->actingAs($this->managerOf(Store::factory()->create()));
        $this->get(route('data-reset.index'))->assertForbidden();
        Livewire::test('pages::admin.data-reset')->assertForbidden();
    }

    public function test_the_factory_reset_section_is_offered_apart(): void
    {
        $this->get(route('data-reset.index'))->assertSee('Factory reset')->assertSee('This erases everything.')->assertDontSee('not available yet');
    }
}
