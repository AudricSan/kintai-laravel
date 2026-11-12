<?php

namespace Tests\Feature\Api\V1;

use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['code' => 'ALPHA']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['login' => 'jane@example.com', 'password' => 'password', 'device_name' => 'Pixel 9'], $overrides);
    }

    private function jane(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email' => 'jane@example.com'], $attributes));
    }

    public function test_login_by_email_returns_a_token_that_works(): void
    {
        $user = $this->jane();

        $response = $this->postJson('/api/v1/auth/login', $this->payload())
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_login_by_employee_code_and_store_code(): void
    {
        $user = $this->employeeOf($this->store, ['employee_code' => 'E042', 'email' => null]);

        $this->postJson('/api/v1/auth/login', $this->payload(['login' => 'E042', 'store_code' => 'alpha']))
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $user = $this->jane();

        $plain = $this->postJson('/api/v1/auth/login', $this->payload())->json('token');

        $row = DB::table('personal_access_tokens')->first();
        $this->assertSame($user->id, (int) $row->tokenable_id);
        $this->assertNotSame($plain, $row->token);
        $this->assertStringNotContainsString(explode('|', $plain)[1], json_encode((array) $row));
        $this->assertSame(hash('sha256', explode('|', $plain)[1]), $row->token);
        $this->assertSame('Pixel 9', $row->name);
    }

    public function test_every_refusal_has_the_same_answer(): void
    {
        $this->jane();
        $this->employeeOf($this->store, ['employee_code' => 'E042', 'email' => null]);
        $this->jane(['email' => 'off@example.com', 'is_active' => false]);

        $attempts = [
            $this->payload(['password' => 'wrong-password']),
            $this->payload(['login' => 'nobody@example.com']),
            $this->payload(['login' => 'off@example.com']),
            $this->payload(['login' => 'E042', 'store_code' => 'ALPHA', 'password' => 'wrong-password']),
            $this->payload(['login' => 'E042', 'store_code' => 'UNKNOWN']),
            $this->payload(['login' => 'E999', 'store_code' => 'ALPHA']),
        ];

        $answers = [];
        foreach ($attempts as $attempt) {
            $response = $this->postJson('/api/v1/auth/login', $attempt)->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
            $answers[] = $response->getContent();
            $this->flushHeaders();
        }

        $this->assertCount(1, array_unique($answers));
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_employee_code_of_another_store_is_refused(): void
    {
        $this->employeeOf($this->store, ['employee_code' => 'E042', 'email' => null]);
        Store::factory()->create(['code' => 'OTHER']);

        $this->postJson('/api/v1/auth/login', $this->payload(['login' => 'E042', 'store_code' => 'OTHER']))
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_inactive_account_gets_no_token(): void
    {
        $this->jane(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', $this->payload())->assertStatus(401);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_login_fields_are_validated(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['login', 'password', 'device_name'], 'errors');

        $this->postJson('/api/v1/auth/login', $this->payload(['login' => ['x']]))->assertStatus(422);
    }

    public function test_expiry_defaults_to_90_days_and_is_bounded(): void
    {
        $this->jane();

        $default = $this->postJson('/api/v1/auth/login', $this->payload())->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, strtotime($default->json('expires_at')), 5);
        $this->assertStringEndsWith('Z', $default->json('expires_at'));

        $short = $this->postJson('/api/v1/auth/login', $this->payload(['expires_in_days' => 7]))->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, strtotime($short->json('expires_at')), 5);

        foreach ([0, 91, -1, 'abc', 1.5] as $invalid) {
            // La limitation de la connexion (5 essais par minute et par identifiant) compte aussi les requêtes mal formées.
            Cache::flush();
            $this->postJson('/api/v1/auth/login', $this->payload(['expires_in_days' => $invalid]))
                ->assertStatus(422)->assertJsonValidationErrors(['expires_in_days'], 'errors');
        }
    }

    public function test_a_short_password_must_be_changed_on_the_website_first(): void
    {
        $this->jane(['password' => '1234']);

        $this->postJson('/api/v1/auth/login', $this->payload(['password' => '1234']))
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_login_is_throttled_like_the_web_login(): void
    {
        $this->jane();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $this->payload(['password' => 'wrong-password']))->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', $this->payload())
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_login_is_throttled_per_ip_across_logins(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/auth/login', $this->payload(['login' => 'user'.$i.'@example.com']))->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', $this->payload(['login' => 'another@example.com']))->assertStatus(429);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithTwoFactor(): array
    {
        $google = new Google2FA;
        $secret = $google->generateSecretKey();

        $user = $this->jane([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
            'two_factor_confirmed_at' => now(),
        ]);

        return [$user, $secret];
    }

    public function test_two_factor_code_is_required_and_no_token_is_issued_without_it(): void
    {
        $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', $this->payload())
            ->assertStatus(401)
            ->assertJsonPath('code', 'two_factor_required')
            ->assertJsonMissingPath('token');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_valid_two_factor_code_gives_a_token(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', $this->payload(['code' => (new Google2FA)->getCurrentOtp($secret)]))
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.two_factor_enabled', true);
    }

    public function test_a_wrong_two_factor_code_is_refused(): void
    {
        $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', $this->payload(['code' => '000000']))
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_two_factor_code')
            ->assertJsonMissingPath('token');

        $this->postJson('/api/v1/auth/login', $this->payload(['recovery_code' => 'not-a-code']))
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_two_factor_code');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', $this->payload(['recovery_code' => 'recovery-code-1']))->assertOk();

        $this->assertNotContains('recovery-code-1', $user->fresh()->recoveryCodes());
        $this->assertContains('recovery-code-2', $user->fresh()->recoveryCodes());

        $this->postJson('/api/v1/auth/login', $this->payload(['recovery_code' => 'recovery-code-1']))
            ->assertStatus(401)->assertJsonPath('code', 'invalid_two_factor_code');
    }

    public function test_a_wrong_password_never_reveals_that_two_factor_is_enabled(): void
    {
        $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', $this->payload(['password' => 'wrong-password']))
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_api_sign_in_is_recorded_without_secret(): void
    {
        $user = $this->jane();

        $plain = $this->postJson('/api/v1/auth/login', $this->payload())->json('token');

        $entry = ActivityEntry::query()->where('action', 'auth.api_login')->firstOrFail();
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame('Pixel 9', $entry->properties['device']);
        $this->assertStringNotContainsString(explode('|', $plain)[1], json_encode($entry->properties));
        $this->assertStringNotContainsString('password', json_encode($entry->properties));
    }

    public function test_a_failed_api_sign_in_is_recorded_with_the_login_only(): void
    {
        $this->postJson('/api/v1/auth/login', $this->payload(['password' => 'wrong-password']))->assertStatus(401);

        $entry = ActivityEntry::query()->where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame('jane@example.com', $entry->properties['login']);
        $this->assertStringNotContainsString('wrong-password', json_encode($entry->properties));
    }

    public function test_login_opens_no_session_and_sets_no_cookie(): void
    {
        $this->jane();

        $response = $this->postJson('/api/v1/auth/login', $this->payload())->assertOk();

        $this->assertSame([], $response->headers->getCookies());
        $this->assertGuest('web');
    }
}
