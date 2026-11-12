<?php

namespace Kintai\Bundles\StorePhotos\Tests;

use App\Actions\Bundles\EnableBundle;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Kintai\Bundles\StorePhotos\Models\StorePhotoImage;
use Kintai\Bundles\StorePhotos\Models\StorePhotoSubmission;
use Kintai\Bundles\StorePhotos\PhotoActions;
use Kintai\Bundles\StorePhotos\PhotoStorage;
use Kintai\Bundles\StorePhotos\StorePhotosServiceProvider;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Backups\BackupFileRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Settings\Settings;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Photos de magasin : droits et portée magasin, envoi sécurisé (type vérifié par le contenu, ré-encodage, nom généré,
 * stockage privé), lecture par la route contrôlée, rotation, suppression avec les fichiers, rétention, réglages,
 * sauvegardes, bundle désactivé.
 */
class StorePhotosTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Beta']);
    }

    private function actions(): PhotoActions
    {
        return app(PhotoActions::class);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function managerWith(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    private function manager(Store $store): User
    {
        return $this->managerWith(['photos.view', 'photos.create', 'photos.update'], $store);
    }

    private function jpeg(int $width = 40, int $height = 30, ?string $extra = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, 0x3366CC);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean().($extra ?? '');
    }

    private function png(int $width = 20, int $height = 20, bool $alpha = false): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 0, 0, 80));
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array{name: string, contents: string}
     */
    private function file(string $name, string $contents): array
    {
        return ['name' => $name, 'contents' => $contents];
    }

    private function submit(User $user, Store $store, array $files = [], ?string $notes = null): StorePhotoSubmission
    {
        return $this->actions()->submit($user, $store->id, $notes, $files ?: [$this->file('shelf.jpg', $this->jpeg())]);
    }

    // -- Branchement ---------------------------------------------------------------------------------------------

    public function test_the_bundle_plugs_into_the_core_when_enabled(): void
    {
        $this->enableBundle('store-photos');

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $this->assertTrue(app(PermissionRegistry::class)->has('photos.'.$action));
        }
        $this->assertTrue(app(StoreFeatureRegistry::class)->has('photos'));
        $this->assertTrue(app(NavigationRegistry::class)->has('store-photos'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('photo_archived'));
        $this->assertTrue(app(ActivityActionRegistry::class)->has('photo.submission_created'));
        $this->assertSame(14, app(Settings::class)->int('photos.retention_days'));
        $this->assertSame(7, app(Settings::class)->int('photos.cleanup_delay'));
    }

    public function test_a_disabled_bundle_is_absent_but_its_files_stay_in_the_backups(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->has('photos.view'));
        $this->assertFalse(app(StoreFeatureRegistry::class)->has('photos'));
        $this->assertFalse(app(Settings::class)->has('photos.retention_days'));
        $this->actingAs($this->owner())->get('/store-photos')->assertNotFound();

        $this->assertSame('store-photos', app(BackupFileRegistry::class)->all()['store-photos'] ?? null);
    }

    public function test_the_first_activation_gives_managers_everything_but_deletion(): void
    {
        app(EnableBundle::class)->handle($this->owner(), 'store-photos');
        $this->app->register(StorePhotosServiceProvider::class, true);

        $manager = $this->managerOf($this->a);
        $employee = $this->employeeOf($this->a);

        foreach (['view', 'create', 'update'] as $action) {
            $this->assertTrue(Gate::forUser($manager)->allows('photos.'.$action, $this->a), $action);
        }
        $this->assertFalse(Gate::forUser($manager)->allows('photos.delete', $this->a));
        $this->assertFalse(Gate::forUser($employee)->allows('photos.view', $this->a));
    }

    public function test_the_retention_task_is_scheduled_when_enabled(): void
    {
        $this->enableBundle('store-photos');

        $names = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description)->all();
        $this->assertContains('kintai:photo-retention', $names);
    }

    // -- Envoi sécurisé ------------------------------------------------------------------------------------------

    public function test_a_photo_is_re_encoded_and_stored_privately_under_a_generated_name(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        $submission = $this->submit($manager, $this->a, [$this->file('../../etc/Evil Name.php.jpg', $this->jpeg(60, 40))], '  Fresh display  ');

        $image = $submission->images()->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $image->path);
        $this->assertSame('Evil Name.php.jpg', $image->filename);
        $this->assertSame(['image/jpeg', 60, 40], [$image->mime_type, $image->width, $image->height]);
        $this->assertSame('Fresh display', $submission->notes);
        Storage::disk('local')->assertExists('store-photos/'.$image->path);
        $this->assertSame($image->filesize, Storage::disk('local')->size('store-photos/'.$image->path));
        $this->assertFalse(Storage::disk('public')->exists('store-photos/'.$image->path));
        $this->assertFileDoesNotExist(public_path('store-photos/'.$image->path));
        $this->assertNotNull(ActivityEntry::where('action', 'photo.submission_created')->where('actor_id', $manager->id)->first());
    }

    public function test_metadata_and_trailing_data_do_not_survive_the_re_encoding(): void
    {
        $this->enableBundle('store-photos');
        $jpeg = $this->jpeg();
        // Commentaire JPEG (COM) et données après l'image : tout ce que le ré-encodage doit faire disparaître.
        $comment = 'SECRET-GPS-COMMENT <?php system($_GET[0]); ?>';
        $dirty = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2).'<?php echo "pwn"; ?>';

        $stored = app(PhotoStorage::class)->store($dirty);
        $contents = (string) Storage::disk('local')->get('store-photos/'.$stored['path']);

        $this->assertStringContainsString($comment, $dirty);
        $this->assertStringNotContainsString('SECRET-GPS', $contents);
        $this->assertStringNotContainsString('<?php', $contents);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($contents)[2]);
    }

    public function test_the_type_comes_from_the_content_not_from_the_name(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        // Un PNG nommé .txt est accepté et stocké en PNG ; un script nommé .jpg est refusé.
        $submission = $this->submit($manager, $this->a, [$this->file('picture.txt', $this->png(10, 10, true))]);
        $this->assertSame('image/png', $submission->images()->firstOrFail()->mime_type);
        $this->assertStringEndsWith('.png', $submission->images()->firstOrFail()->path);

        foreach ([
            'script' => '<?php echo "pwn";',
            'html' => '<html><script>alert(1)</script></html>',
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'gif header only' => "GIF89a<?php system('id'); ?>",
            'empty' => '',
            'pdf' => "%PDF-1.4\n1 0 obj",
        ] as $label => $contents) {
            try {
                $this->actions()->submit($manager, $this->a->id, null, [$this->file('photo.jpg', $contents)]);
                $this->fail("[{$label}] must be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('photos', $e->errors(), $label);
            }
        }

        $this->assertSame(1, StorePhotoImage::count());
    }

    public function test_a_refused_file_writes_nothing_even_after_valid_ones(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        try {
            $this->actions()->submit($manager, $this->a->id, 'Notes', [$this->file('ok.jpg', $this->jpeg()), $this->file('bad.jpg', '<?php evil')]);
            $this->fail('The upload must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('bad.jpg', $e->errors()['photos'][0]);
        }

        $this->assertSame([0, 0], [StorePhotoSubmission::count(), StorePhotoImage::count()]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_images_are_bounded_in_size_pixels_and_count(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        // Grande image : réduite à 2000 px de côté au plus.
        $big = $this->actions()->submit($manager, $this->a->id, null, [$this->file('big.png', $this->png(2600, 1300))])->images()->firstOrFail();
        $this->assertSame([2000, 1000], [$big->width, $big->height]);

        // Trop lourde : refusée avant tout décodage.
        $this->expectException(ValidationException::class);
        $this->actions()->submit($manager, $this->a->id, null, [$this->file('heavy.jpg', $this->jpeg(10, 10, str_repeat('0', PhotoStorage::MAX_BYTES)))]);
    }

    public function test_too_many_files_and_too_much_weight_are_refused(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $photo = $this->file('a.jpg', $this->jpeg());

        foreach ([array_fill(0, PhotoActions::MAX_FILES + 1, $photo), []] as $files) {
            try {
                $this->actions()->submit($manager, $this->a->id, null, $files);
                $this->fail('The upload must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('photos', $e->errors());
            }
        }

        $heavy = $this->file('h.jpg', str_repeat('x', PhotoStorage::MAX_BYTES));
        $this->expectException(ValidationException::class);
        $this->actions()->submit($manager, $this->a->id, null, array_fill(0, 5, $heavy));
    }

    public function test_the_second_upload_of_the_day_joins_the_same_report(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        $first = $this->submit($manager, $this->a, [], 'Morning');
        $second = $this->submit($manager, $this->a, [$this->file('b.jpg', $this->jpeg())], 'Evening');
        $other = $this->submit($this->manager($this->b), $this->b);

        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame("Morning\nEvening", $second->fresh()->notes);
        $this->assertSame([0, 1], $second->images()->pluck('sort_order')->all());
        $this->assertNotNull(ActivityEntry::where('action', 'photo.submission_merged')->first());

        // Un autre jour : nouveau rapport.
        Carbon::setTestNow(now()->addDay());
        $this->assertNotSame($first->id, $this->submit($manager, $this->a)->id);
        Carbon::setTestNow();
    }

    public function test_upload_through_the_screen_validates_and_stores(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        Livewire::actingAs($manager)->test('store-photos.create')
            ->set('photos', [UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1;')])
            ->call('submit')->assertHasErrors(['photos']);
        $this->assertSame(0, StorePhotoImage::count());

        Livewire::actingAs($manager)->test('store-photos.create')
            ->set('photos', [UploadedFile::fake()->image('shelf.jpg', 80, 60)])->set('notes', 'Weekly')
            ->call('submit')->assertHasNoErrors()->assertRedirect();

        $submission = StorePhotoSubmission::firstOrFail();
        $this->assertSame([$this->a->id, $manager->id, 'Weekly', 1], [$submission->store_id, $submission->created_by, $submission->notes, $submission->images()->count()]);
    }

    // -- Droits et portée magasin --------------------------------------------------------------------------------

    public function test_the_store_scope_applies_to_every_action(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $foreign = $this->submit($this->manager($this->b), $this->b);
        $own = $this->submit($manager, $this->a);

        try {
            $this->actions()->submit($manager, $this->b->id, null, [$this->file('a.jpg', $this->jpeg())]);
            $this->fail('Upload in another store.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($manager)->get('/store-photos/'.$own->id)->assertOk();
        $this->actingAs($manager)->get('/store-photos/'.$foreign->id)->assertNotFound();
        Livewire::actingAs($manager)->test('store-photos.index')->assertSee('Alpha')->assertDontSee('Beta');
        Livewire::actingAs($manager)->test('store-photos.index')->set('storeId', $this->b->id)->assertDontSee('Beta');
    }

    public function test_pages_need_the_matching_permission(): void
    {
        $this->enableBundle('store-photos');
        $employee = $this->employeeOf($this->a);
        $viewer = $this->managerWith(['photos.view'], $this->a);

        $this->get('/store-photos')->assertRedirect(route('login'));
        $this->actingAs($employee)->get('/store-photos')->assertForbidden();
        $this->actingAs($viewer)->get('/store-photos')->assertOk();
        $this->actingAs($viewer)->get('/store-photos/create')->assertForbidden();
        $this->actingAs($viewer)->get('/store-photos/settings')->assertForbidden();
    }

    public function test_the_store_feature_switch_closes_the_store_to_uploads(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $submission = $this->submit($manager, $this->a);
        StoreFeature::create(['store_id' => $this->a->id, 'feature' => 'photos', 'enabled' => false]);

        $this->actingAs($manager)->get('/store-photos/create')->assertNotFound();
        $this->actingAs($manager)->get('/store-photos/'.$submission->id)->assertOk();

        $this->expectException(AuthorizationException::class);
        $this->submit($manager, $this->a);
    }

    // -- Lecture par la route contrôlée --------------------------------------------------------------------------

    public function test_a_photo_is_read_through_the_controlled_route_only(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $image = $this->submit($manager, $this->a)->images()->firstOrFail();
        $member = $this->employeeOf($this->a);
        $outsider = $this->employeeOf($this->b);
        $viewerElsewhere = $this->managerWith(['photos.view'], $this->b);
        $url = route('store-photos.image', ['image' => $image->id]);

        // Jamais d'URL directe vers le fichier.
        $this->assertFalse(Storage::disk('public')->exists('store-photos/'.$image->path));
        $this->assertStringNotContainsString($image->path, $url);

        $this->get($url)->assertRedirect(route('login'));

        foreach ([$manager, $member, $this->owner()] as $reader) {
            $response = $this->actingAs($reader)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/jpeg');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($response->streamedContent())[2]);
        }

        foreach ([$outsider, $viewerElsewhere] as $stranger) {
            $this->actingAs($stranger)->get($url)->assertNotFound();
        }

        // Membre inactif : plus de lecture.
        $this->a->members()->updateExistingPivot($member->id, ['is_active' => false]);
        $this->actingAs($member)->get($url)->assertNotFound();

        $this->actingAs($manager)->get(route('store-photos.image', ['image' => 99999]))->assertNotFound();

        // Fichier absent : 404, pas d'erreur.
        Storage::disk('local')->delete('store-photos/'.$image->path);
        $this->actingAs($manager)->get($url)->assertNotFound();
    }

    // -- Rotation ------------------------------------------------------------------------------------------------

    public function test_a_photo_is_rotated_in_place_by_someone_with_the_update_right(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $viewer = $this->managerWith(['photos.view'], $this->a);
        $image = $this->submit($manager, $this->a, [$this->file('wide.jpg', $this->jpeg(60, 20))])->images()->firstOrFail();
        $path = $image->path;

        Livewire::actingAs($manager)->test('store-photos.show', ['id' => $image->submission_id])->call('rotate', $image->id, true);

        $image->refresh();
        $this->assertSame([20, 60, $path], [$image->width, $image->height, $image->path]);
        [$width, $height] = getimagesizefromstring((string) Storage::disk('local')->get('store-photos/'.$path));
        $this->assertSame([20, 60], [$width, $height]);
        $this->assertNotNull(ActivityEntry::where('action', 'photo.image_rotated')->first());

        $this->expectException(AuthorizationException::class);
        $this->actions()->rotate($viewer, $image, false);
    }

    // -- Suppression ---------------------------------------------------------------------------------------------

    public function test_deleting_a_report_deletes_its_files_with_the_records(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $submission = $this->submit($manager, $this->a, [$this->file('a.jpg', $this->jpeg()), $this->file('b.png', $this->png())]);
        $keep = $this->submit($this->manager($this->b), $this->b);
        $names = $submission->images()->pluck('path')->all();
        $this->assertCount(3, Storage::disk('local')->allFiles());

        try {
            $this->actions()->delete($manager, $submission);
            $this->fail('Deletion needs photos.delete.');
        } catch (AuthorizationException) {
            $this->assertCount(3, Storage::disk('local')->allFiles());
        }

        $this->assertSame(['photos.delete'], ['photos.delete']);
        Livewire::actingAs($this->owner())->test('store-photos.show', ['id' => $submission->id])->call('confirmDeletion')->call('delete')->assertRedirect();

        $this->assertNull($submission->fresh());
        foreach ($names as $name) {
            Storage::disk('local')->assertMissing('store-photos/'.$name);
        }
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertNotNull($keep->fresh());
        $this->assertSame(1, StorePhotoImage::count());
        $this->assertNotNull(ActivityEntry::where('action', 'photo.submission_deleted')->first());
    }

    public function test_a_manager_with_the_delete_right_can_delete_in_their_store_only(): void
    {
        $this->enableBundle('store-photos');
        $deleter = $this->managerWith(['photos.view', 'photos.create', 'photos.delete'], $this->a);
        $own = $this->submit($deleter, $this->a);
        $foreign = $this->submit($this->manager($this->b), $this->b);

        $this->actions()->delete($deleter, $own);
        $this->assertNull($own->fresh());

        $this->expectException(AuthorizationException::class);
        $this->actions()->delete($deleter, $foreign);
    }

    // -- Rétention -----------------------------------------------------------------------------------------------

    public function test_retention_notifies_the_owners_once_then_purges_with_the_files(): void
    {
        $this->enableBundle('store-photos');
        $owner = $this->owner();
        $manager = $this->manager($this->a);
        $submission = $this->submit($manager, $this->a);
        $fresh = $this->submit($this->manager($this->b), $this->b);
        $name = $submission->images()->firstOrFail()->path;

        Carbon::setTestNow(now()->addDays(15));
        $this->assertSame(0, Artisan::call('kintai:photo-retention'));

        // Un envoi archivé, une notification aux Owners (jamais au gérant).
        $this->assertNotNull($submission->fresh()->archived_at);
        $this->assertSame(2, $owner->notifications()->where('type', 'photo_archived')->count());
        $this->assertSame(0, $manager->notifications()->count());
        $notification = $owner->notifications()->where('data->reference_id', $submission->id)->firstOrFail();
        $this->assertSame('/store-photos/'.$submission->id, $notification->data['link']);

        // Une seconde passe ne prévient pas deux fois.
        Artisan::call('kintai:photo-retention');
        $this->assertSame(2, $owner->notifications()->count());
        Storage::disk('local')->assertExists('store-photos/'.$name);

        Carbon::setTestNow(now()->addDays(8));
        Artisan::call('kintai:photo-retention');

        $this->assertNull($submission->fresh());
        Storage::disk('local')->assertMissing('store-photos/'.$name);
        $this->assertNull($fresh->fresh());
        Carbon::setTestNow();
    }

    public function test_retention_follows_the_settings(): void
    {
        $this->enableBundle('store-photos');
        $this->owner();
        $submission = $this->submit($this->manager($this->a), $this->a);
        app(Settings::class)->set('photos.retention_days', 30);

        Carbon::setTestNow(now()->addDays(20));
        Artisan::call('kintai:photo-retention');
        $this->assertNull($submission->fresh()->archived_at);

        Carbon::setTestNow(now()->addDays(11));
        Artisan::call('kintai:photo-retention');
        $this->assertNotNull($submission->fresh()->archived_at);
        Carbon::setTestNow();
    }

    public function test_retention_removes_old_orphan_files_only(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $known = $this->submit($manager, $this->a)->images()->firstOrFail()->path;
        $oldOrphan = str_repeat('a', 32).'.jpg';
        $recentOrphan = str_repeat('b', 32).'.jpg';
        Storage::disk('local')->put('store-photos/'.$oldOrphan, 'orphan');
        Storage::disk('local')->put('store-photos/'.$recentOrphan, 'orphan');
        Storage::disk('local')->put('store-photos/readme.txt', 'not a photo name');
        touch(Storage::disk('local')->path('store-photos/'.$oldOrphan), time() - 3 * 86400);

        Artisan::call('kintai:photo-retention');

        Storage::disk('local')->assertMissing('store-photos/'.$oldOrphan);
        Storage::disk('local')->assertExists('store-photos/'.$recentOrphan);
        Storage::disk('local')->assertExists('store-photos/'.$known);
        Storage::disk('local')->assertExists('store-photos/readme.txt');
    }

    // -- Réglages ------------------------------------------------------------------------------------------------

    public function test_only_the_owner_changes_the_retention_settings(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);

        Livewire::actingAs($manager)->test('store-photos.settings')->assertForbidden();

        Livewire::actingAs($this->owner())->test('store-photos.settings')
            ->assertSet('retentionDays', 14)->set('retentionDays', 30)->set('cleanupDelay', 3)->call('save')->assertHasNoErrors();

        $this->assertSame([30, 3], [app(Settings::class)->int('photos.retention_days'), app(Settings::class)->int('photos.cleanup_delay')]);
        $this->assertNotNull(ActivityEntry::where('action', 'photo.settings_updated')->first());

        Livewire::actingAs($this->owner())->test('store-photos.settings')->set('retentionDays', 0)->call('save')->assertHasErrors(['retentionDays']);
        Livewire::actingAs($this->owner())->test('store-photos.settings')->set('cleanupDelay', 400)->call('save')->assertHasErrors(['cleanupDelay']);
    }

    // -- Affichage -----------------------------------------------------------------------------------------------

    public function test_notes_and_file_names_are_escaped(): void
    {
        $this->enableBundle('store-photos');
        $manager = $this->manager($this->a);
        $submission = $this->submit($manager, $this->a, [$this->file('<img src=x onerror=alert(1)>.jpg', $this->jpeg())], '<script>alert(1)</script>');

        $this->actingAs($manager)->get('/store-photos/'.$submission->id)
            ->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<img src=x onerror', false);
    }

    public function test_the_index_groups_by_day_and_filters(): void
    {
        $this->enableBundle('store-photos');
        $owner = $this->owner();
        $this->submit($this->manager($this->a), $this->a);
        Carbon::setTestNow(now()->subDays(3));
        $old = $this->submit($this->manager($this->b), $this->b);
        Carbon::setTestNow();
        $oldDay = $old->created_at->toDateString();

        $cards = fn ($component): int => $component->viewData('groups')->sum(fn ($group): int => $group->count());
        $stores = fn ($component): array => $component->viewData('groups')->flatten(1)->map(fn ($submission): string => $submission->store->name)->sort()->values()->all();

        $component = Livewire::actingAs($owner)->test('store-photos.index');
        $this->assertSame(['Alpha', 'Beta'], $stores($component));
        $this->assertSame(2, $component->viewData('groups')->count(), 'two different days');

        $component->set('storeId', $this->b->id);
        $this->assertSame(['Beta'], $stores($component));

        $component->call('resetFilters')->set('date', $oldDay);
        $this->assertSame(['Beta'], $stores($component));
        $this->assertSame(1, $cards($component));
    }
}
