<?php

namespace Tests\Feature\Auth;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Store}
     */
    private function employee(string $code = 'E042'): array
    {
        $store = Store::factory()->create(['code' => 'SHIBUYA']);
        $user = User::factory()->create(['employee_code' => $code]);
        $store->members()->attach($user);

        return [$user, $store];
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_home_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_login_by_email_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'Jane.Doe@Example.com']);

        $this->assertSame('jane.doe@example.com', $user->email);

        $this->post(route('login.store'), ['login' => '  JANE.DOE@example.COM ', 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_by_employee_code_and_store_code(): void
    {
        [$user] = $this->employee();

        $this->post(route('login.store'), ['login' => 'E042', 'store_code' => 'shibuya', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_store_code_saved_in_lowercase_still_matches(): void
    {
        [$user, $store] = $this->employee();
        $store->update(['code' => ' shibuya ']);

        $this->assertSame('SHIBUYA', $store->fresh()->code);

        $this->post(route('login.store'), ['login' => 'E042', 'store_code' => 'Shibuya', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_employee_code_is_not_lowercased(): void
    {
        [$user] = $this->employee('AbC1');

        $this->post(route('login.store'), ['login' => 'AbC1', 'store_code' => 'SHIBUYA', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_employee_code_without_store_code_is_refused(): void
    {
        $this->employee();

        $this->post(route('login.store'), ['login' => 'E042', 'password' => 'password'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_refusals_all_give_the_same_error(): void
    {
        [, $store] = $this->employee();
        Store::factory()->create(['code' => 'OTHER']);
        $inactive = User::factory()->inactive()->create(['employee_code' => 'E100']);
        $store->members()->attach($inactive);
        $deleted = User::factory()->create(['employee_code' => 'E200']);
        $store->members()->attach($deleted);
        $deleted->delete();

        $attempts = [
            'wrong store' => ['login' => 'E042', 'store_code' => 'OTHER', 'password' => 'password'],
            'unknown store' => ['login' => 'E042', 'store_code' => 'NOPE', 'password' => 'password'],
            'inactive' => ['login' => 'E100', 'store_code' => 'SHIBUYA', 'password' => 'password'],
            'deleted' => ['login' => 'E200', 'store_code' => 'SHIBUYA', 'password' => 'password'],
            'wrong password' => ['login' => 'E042', 'store_code' => 'SHIBUYA', 'password' => 'wrong-password'],
            'unknown email' => ['login' => 'nobody@example.com', 'password' => 'password'],
            'inactive email' => ['login' => $inactive->email, 'password' => 'password'],
        ];

        $messages = [];
        foreach ($attempts as $label => $payload) {
            RateLimiter::clear('login');
            $this->post(route('login.store'), $payload)->assertSessionHasErrors('login');
            $this->assertGuest();
            $messages[$label] = session('errors')->first('login');
        }

        $this->assertCount(1, array_unique($messages), 'Les refus doivent porter le même message.');
    }

    public function test_sixth_attempt_is_blocked_even_with_the_right_password(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['login' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('login');
        }

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_short_password_raises_the_password_reminder(): void
    {
        $user = User::factory()->create(['password' => 'short']);

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'short']);

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->refresh()->must_change_password);

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_long_password_does_not_force_a_password_change(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_employee_code_login_goes_through_the_two_factor_challenge(): void
    {
        [$user] = $this->employee();
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('login.store'), ['login' => 'E042', 'store_code' => 'SHIBUYA', 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }
}
