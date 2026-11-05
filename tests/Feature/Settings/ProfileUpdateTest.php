<?php

namespace Tests\Feature\Settings;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Contracts\Profile\ProfileSectionRegistry;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_contact_details_and_sharing_choices_are_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('phone', '03-1234-5678')
            ->set('mobile_phone', '090-0000-1111')
            ->set('postal_code', '150-0001')
            ->set('address', '1-1 Shibuya')
            ->set('share_email', true)
            ->set('share_phone', true)
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->share_email);
        $this->assertTrue($user->share_phone);
        $this->assertFalse($user->share_mobile_phone);
        $this->assertSame('03-1234-5678', $user->profile->phone);
        $this->assertSame('090-0000-1111', $user->profile->mobile_phone);
        $this->assertSame('150-0001', $user->profile->postal_code);
        $this->assertSame('1-1 Shibuya', $user->profile->address);
    }

    public function test_existing_profile_row_is_updated_not_duplicated(): void
    {
        $user = User::factory()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id, 'gender' => 'female']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('phone', '111')
            ->call('updateProfileInformation');

        $this->assertSame(1, EmployeeProfile::where('user_id', $user->id)->count());
        $this->assertSame('female', $user->profile()->first()->gender);
        $this->assertSame('111', $user->profile()->first()->phone);
    }

    #[DataProvider('readOnlyFields')]
    public function test_identity_fields_cannot_be_modified_even_by_forcing_the_property(string $field): void
    {
        $user = User::factory()->create(['email' => 'keep@example.com']);
        $original = $user->only(['name', 'first_name', 'last_name', 'email']);
        $this->actingAs($user);

        $refused = false;
        try {
            Livewire::test('pages::settings.profile')->set($field, 'hacked')->call('updateProfileInformation');
        } catch (\Throwable) {
            // La propriété n'existe pas sur le composant : la modification est refusée.
            $refused = true;
        }

        $this->assertTrue($refused);
        $this->assertSame($original, $user->fresh()->only(['name', 'first_name', 'last_name', 'email']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readOnlyFields(): array
    {
        return ['name' => ['name'], 'first_name' => ['first_name'], 'last_name' => ['last_name'], 'email' => ['email']];
    }

    public function test_identity_fields_are_shown_read_only(): void
    {
        $user = User::factory()->create(['first_name' => 'Jeanne', 'email' => 'jeanne@example.com']);
        $this->actingAs($user);

        $this->get(route('profile.edit'))->assertSee('Jeanne')->assertSee('jeanne@example.com');
    }

    public function test_locale_is_saved_and_applied_to_requests(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')->set('locale', 'ja')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertSame('ja', $user->fresh()->locale);

        $this->get(route('dashboard'))->assertOk();
        $this->assertSame('ja', app()->getLocale());

        Livewire::test('pages::settings.profile')->set('locale', '')->call('updateProfileInformation');
        $this->assertNull($user->fresh()->locale);

        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    public function test_unsupported_locale_is_refused(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::settings.profile')->set('locale', 'xx')->call('updateProfileInformation')->assertHasErrors('locale');
        $this->assertNull($user->fresh()->locale);
    }

    public function test_settings_navigation_comes_from_the_section_registry(): void
    {
        $this->actingAs(User::factory()->create());

        app(ProfileSectionRegistry::class)->register('extra', 'Extra section', 'dashboard', 15);

        $this->get(route('profile.edit'))->assertSee('Extra section');
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertSoftDeleted($user);
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
