<?php

namespace Tests\Feature\Actions;

use App\Actions\AnonymizeUser;
use App\Events\UserAnonymized;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class AnonymizeUserTest extends TestCase
{
    use CreatesAccess, RefreshDatabase;

    public function test_identity_is_erased_and_related_data_removed(): void
    {
        $this->seedRoles();
        Event::fake([UserAnonymized::class]);
        Storage::fake('public');
        Storage::disk('public')->put('avatars/me.png', 'x');
        config(['session.driver' => 'database']);

        $store = Store::factory()->create();
        $user = User::factory()->withTwoFactor()->create([
            'employee_code' => 'E001',
            'furigana_first_name' => 'ハナ',
            'avatar_path' => 'avatars/me.png',
            'share_email' => true,
            'share_phone' => true,
            'share_mobile_phone' => true,
        ]);
        $store->members()->attach($user);
        EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $user->assignRole($this->role('manager'), $store);
        DB::table('passkeys')->insert([
            'user_id' => $user->id, 'name' => 'key', 'credential_id' => 'abc', 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'sess1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $shift = Shift::factory()->create(['user_id' => $user->id, 'store_id' => $store->id]);
        $oldToken = $user->remember_token;

        app(AnonymizeUser::class)->handle($user);

        $fresh = User::withTrashed()->findOrFail($user->id);
        $this->assertSoftDeleted($fresh);
        $this->assertSame('[Supprimé]', $fresh->first_name);
        $this->assertSame('', $fresh->last_name);
        $this->assertSame('deleted-'.$user->id.'@kintai.invalid', $fresh->email);
        $this->assertNull($fresh->employee_code);
        $this->assertNull($fresh->furigana_first_name);
        $this->assertNull($fresh->avatar_path);
        $this->assertFalse($fresh->is_active);
        $this->assertFalse($fresh->share_email || $fresh->share_phone || $fresh->share_mobile_phone);
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNotSame($oldToken, $fresh->remember_token);

        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('passkeys', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'user_id' => $user->id]);
        Storage::disk('public')->assertMissing('avatars/me.png');
        Event::assertDispatched(UserAnonymized::class, fn (UserAnonymized $e) => $e->userId === $user->id);
    }

    public function test_old_email_can_no_longer_log_in(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        app(AnonymizeUser::class)->handle($user);

        $this->post(route('login.store'), ['login' => 'old@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_user_can_anonymize_their_own_account_from_settings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertSoftDeleted($user);
        $this->assertSame('deleted-'.$user->id.'@kintai.invalid', User::withTrashed()->find($user->id)?->email);
        $this->assertFalse(auth()->check());
    }

    public function test_owner_cannot_delete_their_own_account(): void
    {
        $this->seedRoles();
        $owner = $this->owner();
        $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotSoftDeleted($owner);
        $this->assertTrue($owner->refresh()->is_active);
    }
}
