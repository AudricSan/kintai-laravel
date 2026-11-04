<?php

namespace Tests\Feature\Api\V1;

use App\Actions\AnonymizeUser;
use App\Actions\ChangePassword;
use App\Models\ActivityEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UsesApi;

    public function test_a_request_without_token_is_refused(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer 1|invalid'])->assertStatus(401);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user, 'Old phone', now()->subMinute());

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_a_token_works_until_it_expires(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user, 'Phone', now()->addMinutes(5));

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertOk();

        $this->travel(6)->minutes();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token_only(): void
    {
        $user = User::factory()->create();
        $first = $this->tokenFor($user, 'First');
        $second = $this->tokenFor($user, 'Second');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$first])->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->assertNull($this->tokenRecord($first));
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$first])->assertStatus(401);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$second])->assertOk();

        $entry = ActivityEntry::query()->where('action', 'auth.api_logout')->firstOrFail();
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame('First', $entry->properties['device']);
    }

    public function test_tokens_are_listed_without_their_value(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->tokenFor($other, 'Not mine');
        $this->tokenFor($user, 'Tablet');
        $current = $this->tokenFor($user, 'Phone', now()->addDays(10));

        $response = $this->getJson('/api/v1/auth/tokens', ['Authorization' => 'Bearer '.$current])->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data' => [['id', 'name', 'created_at', 'last_used_at', 'expires_at', 'current']]]);
        $this->assertStringNotContainsString(explode('|', $current)[1], (string) $response->getContent());
        $this->assertStringNotContainsString('Not mine', (string) $response->getContent());
        $this->assertEqualsCanonicalizing([false, true], array_column($response->json('data'), 'current'));
        $this->assertSame('Phone', collect($response->json('data'))->firstWhere('current', true)['name']);
    }

    public function test_a_user_revokes_his_own_other_token(): void
    {
        $user = User::factory()->create();
        $current = $this->tokenFor($user, 'Phone');
        $tablet = $this->tokenRecord($this->tokenFor($user, 'Tablet'));

        $this->deleteJson('/api/v1/auth/tokens/'.$tablet->id, [], ['Authorization' => 'Bearer '.$current])->assertNoContent();

        $this->assertNull(PersonalAccessToken::find($tablet->id));
        $this->assertTrue($user->tokens()->where('name', 'Phone')->exists());

        $entry = ActivityEntry::query()->where('action', 'auth.api_token_revoked')->firstOrFail();
        $this->assertSame('Tablet', $entry->properties['device']);
    }

    public function test_a_user_cannot_revoke_the_token_of_another(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->tokenRecord($this->tokenFor($other, 'Theirs'));

        $this->deleteJson('/api/v1/auth/tokens/'.$theirs->id, [], ['Authorization' => 'Bearer '.$this->tokenFor($user)])
            ->assertStatus(404)->assertJsonPath('code', 'not_found');

        $this->assertNotNull(PersonalAccessToken::find($theirs->id));
        $this->assertSame(0, ActivityEntry::query()->where('action', 'auth.api_token_revoked')->count());
    }

    public function test_a_password_change_revokes_every_token(): void
    {
        $this->actingAs($user = User::factory()->create());
        $first = $this->tokenFor($user, 'First');
        $second = $this->tokenFor($user, 'Second');

        app(ChangePassword::class)->handle($user, 'a-brand-new-password');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($this->tokenRecord($first));
        $this->assertNull($this->tokenRecord($second));
    }

    public function test_a_password_reset_by_a_manager_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);

        $user->forceFill(['password' => 'another-new-password'])->save();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_other_profile_changes_keep_the_tokens(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);

        $user->forceFill(['name' => 'New name', 'locale' => 'fr'])->save();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_deactivating_an_account_revokes_its_tokens(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user);

        $user->forceFill(['is_active' => false])->save();

        $this->assertSame(0, $user->tokens()->count());
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_an_account_deactivated_behind_the_models_back_is_refused_and_loses_its_tokens(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user);

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_anonymized_account_loses_its_tokens(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user);

        app(AnonymizeUser::class)->handle($user);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_last_use_of_the_token_is_recorded(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user);
        $this->assertNull($this->tokenRecord($plain)->last_used_at);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertOk();

        $this->assertNotNull($this->tokenRecord($plain)->last_used_at);
    }
}
