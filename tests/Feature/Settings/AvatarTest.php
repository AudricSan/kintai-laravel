<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function transparentPng(string $name = 'avatar.png'): File
    {
        $image = imagecreatetruecolor(500, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 50, 400, 250, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));

        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    public function test_transparent_png_is_stored_as_a_square_png(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', $this->transparentPng())
            ->call('saveAvatar')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertMatchesRegularExpression('#^avatars/user_'.$user->id.'_[a-z0-9]+\.png$#', $user->avatar_path);

        $info = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));
        $this->assertSame([256, 256, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function test_jpeg_is_stored_as_jpeg_and_old_file_is_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', $this->transparentPng())
            ->call('saveAvatar');
        $old = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($old);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('photo.jpg', 800, 600))
            ->call('saveAvatar')
            ->assertHasNoErrors();

        $new = $user->fresh()->avatar_path;
        $this->assertNotSame($old, $new);
        $this->assertStringEndsWith('.jpg', $new);
        Storage::disk('local')->assertMissing($old);

        $info = getimagesizefromstring(Storage::disk('local')->get($new));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_non_image_is_refused(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'))
            ->call('saveAvatar')
            ->assertHasErrors('avatar');

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->createWithContent('fake.png', 'not really an image'))
            ->call('saveAvatar')
            ->assertHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_image_over_5_mb_is_refused(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('big.jpg')->size(5200))
            ->call('saveAvatar')
            ->assertHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_can_be_removed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('avatar', $this->transparentPng())
            ->call('saveAvatar');
        $path = $user->fresh()->avatar_path;

        Livewire::test('pages::settings.profile')->call('removeAvatar');

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_avatar_is_served_to_any_signed_in_user_with_private_cache(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        Livewire::test('pages::settings.profile')->set('avatar', $this->transparentPng())->call('saveAvatar');
        $owner->refresh();

        $this->actingAs(User::factory()->create())
            ->get(route('avatar.show', $owner))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=86400, private');

        $this->assertStringContainsString('avatar/'.$owner->id, $owner->avatarUrl());
    }

    public function test_avatar_is_404_without_avatar_and_requires_login(): void
    {
        $user = User::factory()->create();

        $this->get(route('avatar.show', $user))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('avatar.show', $user))->assertNotFound();
    }

    public function test_menu_shows_avatar_when_present_else_initials(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertDontSee('avatar/'.$user->id);

        Livewire::test('pages::settings.profile')->set('avatar', $this->transparentPng())->call('saveAvatar');

        $this->get(route('dashboard'))->assertSee('avatar/'.$user->id, false);
    }
}
