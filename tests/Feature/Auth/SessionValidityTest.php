<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class SessionValidityTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_password_keeps_current_session_and_drops_other_sessions(): void
    {
        $user = User::factory()->create();
        $oldRememberToken = $user->remember_token;

        // Session courante : ouverte par une vraie connexion, donc avec son empreinte en session.
        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);
        $this->get(route('dashboard'))->assertOk();
        $staleHash = session('password_hash_web');
        $this->assertNotNull($staleHash);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);

        // La session courante reste valide.
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($staleHash, session('password_hash_web'));

        // Une autre session, restée sur l'ancienne empreinte, est déconnectée.
        Auth::forgetGuards();
        $this->flushSession();
        $this->withSession(['password_hash_web' => $staleHash])
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_change_regenerates_remember_token(): void
    {
        $user = User::factory()->create();
        $token = $user->remember_token;

        $user->update(['password' => 'another-password']);

        $this->assertNotSame($token, $user->refresh()->remember_token);
    }

    public function test_saving_without_password_change_keeps_remember_token(): void
    {
        $user = User::factory()->create();
        $token = $user->remember_token;

        $user->update(['name' => 'Someone else']);

        $this->assertSame($token, $user->refresh()->remember_token);
    }

    public function test_account_deactivated_during_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deleted_account_is_no_longer_authenticated(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);
        $this->get(route('dashboard'))->assertOk();

        $user->delete();
        Auth::forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_password_reminder_does_not_redirect(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_banner_is_shown_only_while_the_reminder_is_active(): void
    {
        $this->actingAs(User::factory()->mustChangePassword()->create())
            ->get(route('dashboard'))
            ->assertSee('data-test="password-reminder-banner"', false)
            ->assertSee(route('security.edit'), false);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertDontSee('password-reminder-banner');
    }

    public function test_modal_is_opened_once_per_login(): void
    {
        $user = User::factory()->create(['password' => 'short']);

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'short']);
        $this->assertTrue(session('password_reminder'));

        $this->get(route('dashboard'))->assertSee('data-test="password-reminder-modal"', false);
        $this->assertNull(session('password_reminder'));

        $this->get(route('dashboard'))->assertSee('password-reminder-banner', false);
        $this->assertNull(session('password_reminder'));
    }

    public function test_no_modal_flag_for_a_normal_login(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);

        $this->assertNull(session('password_reminder'));
    }

    public function test_reminder_is_lifted_after_changing_password_on_security_page(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'brand-new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);
        $this->get(route('dashboard'))->assertDontSee('password-reminder-banner');
    }

    public function test_reminder_is_lifted_after_password_reset(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_reminder_stays_if_the_same_save_sets_it_explicitly(): void
    {
        $user = User::factory()->create();

        $user->forceFill(['password' => 'default-password', 'must_change_password' => true])->save();

        $this->assertTrue($user->refresh()->must_change_password);
    }
}
