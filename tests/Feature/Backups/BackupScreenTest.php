<?php

namespace Tests\Feature\Backups;

use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesBackupDirectory;
use Tests\TestCase;

class BackupScreenTest extends TestCase
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

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    public function test_only_the_owner_reaches_the_screen_and_the_download(): void
    {
        $backup = $this->backups()->create();

        $this->get(route('backups.index'))->assertOk()->assertSee('Backups');
        $this->get(route('backups.download', $backup->id))->assertOk();

        $store = Store::factory()->create();
        $this->actingAs($this->managerOf($store));
        $this->get(route('backups.index'))->assertForbidden();
        $this->get(route('backups.download', $backup->id))->assertForbidden();
        Livewire::test('pages::admin.backups')->assertForbidden();

        auth()->logout();
        $this->get(route('backups.index'))->assertRedirect(route('login'));
        $this->get(route('backups.download', $backup->id))->assertRedirect(route('login'));
    }

    public function test_the_menu_entry_is_for_the_owner_only(): void
    {
        $this->get(route('dashboard'))->assertSee(route('backups.index'));

        $this->actingAs($this->managerOf(Store::factory()->create()));
        $this->get(route('dashboard'))->assertDontSee(route('backups.index'));
    }

    public function test_the_list_shows_date_note_size_driver_and_version(): void
    {
        $this->backups()->create('Quarterly close');

        $this->get(route('backups.index'))
            ->assertSee('Quarterly close')
            ->assertSee('sqlite')
            ->assertSee(config('kintai.version'))
            ->assertSee('data-test="download-backup"', false)
            ->assertSee('data-test="restore-backup"', false);
    }

    public function test_it_creates_a_backup_with_a_note_and_logs_it(): void
    {
        Livewire::test('pages::admin.backups')
            ->set('note', 'My note')
            ->call('create')
            ->assertSet('note', '')
            ->assertSee('My note');

        $this->assertCount(1, $this->backups()->all());
        $this->assertSame('My note', $this->backups()->all()[0]->note);
        $this->assertDatabaseHas('activity_entries', ['action' => 'backup.created', 'actor_id' => $this->owner->id]);
    }

    public function test_a_too_long_note_is_refused(): void
    {
        Livewire::test('pages::admin.backups')
            ->set('note', str_repeat('a', 201))
            ->call('create')
            ->assertHasErrors('note');

        $this->assertSame([], $this->backups()->all());
    }

    public function test_it_deletes_one_backup_after_confirmation(): void
    {
        $keep = $this->backups()->create();
        $remove = $this->backups()->create();

        Livewire::test('pages::admin.backups')
            ->call('confirmDelete', $remove->id)
            ->assertSet('pendingId', $remove->id)
            ->call('deletePending');

        $this->assertSame([$keep->id], array_map(fn ($b) => $b->id, $this->backups()->all()));
        $this->assertDatabaseHas('activity_entries', ['action' => 'backup.deleted']);
    }

    public function test_it_deletes_every_backup(): void
    {
        $this->backups()->create();
        $this->backups()->create();

        Livewire::test('pages::admin.backups')->call('confirmDeleteAll')->call('deleteAll');

        $this->assertSame([], $this->backups()->all());
        $entry = ActivityEntry::query()->where('action', 'backup.deleted_all')->firstOrFail();
        $this->assertSame(2, $entry->properties['deleted']);
    }

    public function test_an_identifier_outside_the_list_is_never_acted_upon(): void
    {
        $backup = $this->backups()->create();

        Livewire::test('pages::admin.backups')
            ->call('confirmDelete', '../../.env')
            ->assertSet('pendingId', '')
            ->call('confirmRestore', 'backup_20260101_000000_00000000.zip')
            ->assertSet('pendingId', '')
            ->call('deletePending');

        $this->assertCount(1, $this->backups()->all());
        $this->assertNotNull($this->backups()->find($backup->id));
    }

    public function test_the_pending_identifier_cannot_be_forged_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pages::admin.backups')->set('pendingId', 'x');
    }

    public function test_the_settings_are_saved_and_validated(): void
    {
        Livewire::test('pages::admin.backups')
            ->set('auto_enabled', false)
            ->set('max_keep', '30')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertFalse($this->settings()->backupAutoEnabled());
        $this->assertSame(30, $this->settings()->backupMaxKeep());
        $this->assertDatabaseHas('activity_entries', ['action' => 'backup.settings_updated']);

        foreach (['-1', '366', 'abc', '', '1.5'] as $value) {
            Livewire::test('pages::admin.backups')->set('max_keep', $value)->call('saveSettings')->assertHasErrors('max_keep');
        }

        $this->assertSame(30, $this->settings()->backupMaxKeep());
    }

    public function test_the_automatic_backup_state_is_shown(): void
    {
        $this->settings()->setMany(['backup.last_failure' => '2026-11-05 03:00:00 - disk full']);

        $this->get(route('backups.index'))->assertSee('The last automatic backup failed.')->assertSee('disk full');

        $this->settings()->setMany(['backup.last_failure' => '', 'backup.last_success_at' => '2026-11-05T03:00:00Z']);

        $this->get(route('backups.index'))->assertDontSee('The last automatic backup failed.')->assertSee('Last successful automatic backup');
    }

    public function test_restoring_asks_for_the_password_then_signs_the_owner_out(): void
    {
        $store = Store::factory()->create(['name' => 'Before']);
        $backup = $this->backups()->create();
        $store->update(['name' => 'After']);

        Livewire::test('pages::admin.backups')
            ->call('confirmRestore', $backup->id)
            ->assertSet('pendingId', $backup->id)
            ->set('password', 'wrong')
            ->call('restorePending')
            ->assertHasErrors('password');

        $this->assertSame('After', Store::query()->findOrFail($store->id)->name);
        $this->assertAuthenticated();

        Livewire::test('pages::admin.backups')
            ->call('confirmRestore', $backup->id)
            ->set('password', 'password')
            ->call('restorePending')
            ->assertRedirect(route('login'));

        $this->assertSame('Before', Store::query()->findOrFail($store->id)->name);
        $this->assertGuest();
        $this->assertSame('The backup was restored. Sign in again to continue.', session('status'));
    }

    public function test_the_restore_modal_explains_the_consequences(): void
    {
        $backup = $this->backups()->create();

        Livewire::test('pages::admin.backups')
            ->call('confirmRestore', $backup->id)
            ->assertSee('everything entered since then is lost')
            ->assertSee('safety backup')
            ->assertSee('signed out');
    }

    public function test_the_download_streams_the_archive_with_a_safe_name_and_is_logged(): void
    {
        $backup = $this->backups()->create();

        $response = $this->get(route('backups.download', $backup->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('filename='.$backup->id, (string) $response->headers->get('Content-Disposition'));
        $this->assertSame((string) filesize($backup->path), $response->headers->get('Content-Length'));
        $this->assertSame(file_get_contents($backup->path), $response->streamedContent());
        $this->assertDatabaseHas('activity_entries', ['action' => 'backup.downloaded']);
    }

    public function test_the_download_refuses_anything_but_a_listed_archive(): void
    {
        $this->backups()->create();
        // Identifiant valide mais absent de la liste, puis motifs hors format ou traversées.
        $this->get(route('backups.download', 'backup_20260101_000000_00000000.zip'))->assertNotFound();

        foreach (['..%2F..%2F.env', 'backup_20260101_000000_00000000.zip%00.php', '.env', 'backup_..zip', '%2e%2e%2f%2e%2e%2f.env'] as $raw) {
            $this->get('/admin/backups/'.$raw.'/download')->assertNotFound();
        }

        $this->get('/admin/backups/../../.env/download')->assertNotFound();
    }

    public function test_a_download_for_a_symlink_or_outside_file_is_refused(): void
    {
        $this->backups()->create();
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup_20260303_030303_abcdef12.zip';
        file_put_contents($outside, 'outside');

        try {
            $this->get(route('backups.download', 'backup_20260303_030303_abcdef12.zip'))->assertNotFound();
        } finally {
            @unlink($outside);
        }
    }
}
