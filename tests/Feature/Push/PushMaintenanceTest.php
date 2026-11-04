<?php

namespace Tests\Feature\Push;

use App\Actions\AnonymizeUser;
use App\Actions\Push\PurgeInactivePushTargets;
use App\Actions\Push\RegisterDeviceToken;
use App\Actions\Push\RemoveDeviceToken;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

class PushMaintenanceTest extends TestCase
{
    use FakesPush;
    use RefreshDatabase;

    // Jetons d'appareil

    public function test_a_device_token_is_registered_and_refreshed(): void
    {
        $user = User::factory()->create();

        app(RegisterDeviceToken::class)->handle($user, ' token-1 ', 'Android');
        app(RegisterDeviceToken::class)->handle($user, 'token-1', 'ios');

        $this->assertSame(1, DevicePushToken::count());
        $token = DevicePushToken::firstOrFail();
        $this->assertSame('token-1', $token->token);
        $this->assertSame('ios', $token->platform);
        $this->assertNotNull($token->last_used_at);
    }

    public function test_a_device_token_moves_to_the_new_account_on_a_shared_device(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        app(RegisterDeviceToken::class)->handle($first, 'shared', 'android');
        app(RegisterDeviceToken::class)->handle($second, 'shared', 'android');

        $this->assertSame(1, DevicePushToken::count());
        $this->assertSame($second->id, DevicePushToken::firstOrFail()->user_id);
    }

    public function test_an_invalid_token_or_platform_is_refused(): void
    {
        $user = User::factory()->create();

        foreach ([['', 'ios'], [str_repeat('a', 256), 'ios'], ['token', 'web'], ['token', '']] as [$token, $platform]) {
            try {
                app(RegisterDeviceToken::class)->handle($user, $token, $platform);
                $this->fail('Should have been refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, DevicePushToken::count());
    }

    public function test_a_user_removes_only_their_own_device_token(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(RegisterDeviceToken::class)->handle($owner, 'mine', 'ios');

        $this->assertFalse(app(RemoveDeviceToken::class)->handle($other, 'mine'));
        $this->assertSame(1, DevicePushToken::count());
        $this->assertTrue(app(RemoveDeviceToken::class)->handle($owner, 'mine'));
        $this->assertSame(0, DevicePushToken::count());
    }

    // Purge

    public function test_inactive_subscriptions_and_tokens_are_purged_after_180_days(): void
    {
        $user = User::factory()->create();
        $old = now()->subDays(181);
        $recent = now()->subDays(179);

        $staleUsed = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/stale-used');
        $staleUsed->forceFill(['last_used_at' => $old])->save();
        $freshUsed = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/fresh-used');
        $freshUsed->forceFill(['last_used_at' => $recent])->save();
        // Jamais utilisé : c'est updated_at qui fait foi.
        $staleNever = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/stale-never');
        WebPushSubscription::query()->whereKey($staleNever->id)->update(['updated_at' => $old]);
        $freshNever = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/fresh-never');
        // Ancien mais utilisé récemment : conservé.
        $oldButUsed = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/old-but-used');
        WebPushSubscription::query()->whereKey($oldButUsed->id)->update(['updated_at' => $old, 'last_used_at' => $recent]);

        $staleToken = DevicePushToken::query()->create(['user_id' => $user->id, 'token' => 'stale', 'platform' => 'ios', 'last_used_at' => $old]);
        DevicePushToken::query()->create(['user_id' => $user->id, 'token' => 'fresh', 'platform' => 'ios', 'last_used_at' => $recent]);

        $result = app(PurgeInactivePushTargets::class)->handle();

        $this->assertSame(['subscriptions' => 2, 'tokens' => 1], $result);
        $this->assertEqualsCanonicalizing(
            [$freshUsed->id, $freshNever->id, $oldButUsed->id],
            WebPushSubscription::query()->pluck('id')->all(),
        );
        $this->assertNull(DevicePushToken::find($staleToken->id));
        $this->assertSame(1, DevicePushToken::count());
    }

    public function test_the_purge_command_also_purges_push_targets(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user)->forceFill(['last_used_at' => now()->subDays(200)])->save();

        Artisan::call('kintai:notifications:purge');

        $this->assertSame(0, WebPushSubscription::count());
    }

    public function test_deleting_a_user_account_removes_their_push_targets(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        app(RegisterDeviceToken::class)->handle($user, 'tok', 'ios');

        app(AnonymizeUser::class)->handle($user);

        $this->assertSame(0, WebPushSubscription::count());
        $this->assertSame(0, DevicePushToken::count());
    }
}
