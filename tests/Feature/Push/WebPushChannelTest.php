<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationType;
use App\Push\WebPushEndpointValidator;
use App\Push\WebPushResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesPush;
use Tests\Support\Push\FakeWebPushSender;
use Tests\TestCase;

class WebPushChannelTest extends TestCase
{
    use FakesPush;
    use RefreshDatabase;

    private FakeWebPushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureVapid();
        $this->sender = $this->fakeWebPushSender();
    }

    private function notification(string $type = 'shift_updated'): KintaiNotification
    {
        return KintaiNotification::make($type, 'A shift was added for you on :date from :start to :end (:store).', [
            'date' => '2026-11-10', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha',
        ], '/my-shifts', 42);
    }

    // via()

    public function test_push_channels_are_added_with_vapid_keys_subscriptions_and_the_preference(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);

        $this->assertSame(['database', WebPushChannel::class], $this->notification()->via($user));
    }

    public function test_no_push_channel_without_a_subscription(): void
    {
        $this->assertSame(['database'], $this->notification()->via(User::factory()->create()));
    }

    public function test_no_web_push_without_vapid_keys(): void
    {
        config(['kintai.webpush.public_key' => null, 'kintai.webpush.private_key' => null]);
        $user = User::factory()->create();
        $this->subscribe($user);

        $this->assertSame(['database'], $this->notification()->via($user));
        $user->notify($this->notification());
        $this->assertSame([], $this->sender->sent);
    }

    public function test_no_push_when_the_user_turned_the_push_preference_off(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        app(NotificationPreferences::class)->set($user, 'shift_updated', NotificationType::CHANNEL_PUSH, false);

        $this->assertSame(['database'], $this->notification()->via($user));
        $this->assertContains(WebPushChannel::class, $this->notification('shift_deleted')->via($user));
    }

    public function test_the_fcm_channel_needs_fcm_enabled_and_a_token(): void
    {
        $user = User::factory()->create();
        $user->devicePushTokens()->create(['token' => 'tok', 'platform' => 'android']);

        $this->assertSame(['database'], $this->notification()->via($user));

        config(['kintai.fcm.enabled' => true, 'kintai.fcm.project_id' => 'demo']);
        $this->assertSame(['database', FcmChannel::class], $this->notification()->via($user));
    }

    // Charge utile

    public function test_the_payload_carries_text_in_the_recipients_language_link_type_and_ids(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);
        $this->subscribe($user);

        $user->notify($this->notification('shift_assigned'));

        $this->assertCount(1, $this->sender->sent);
        $payload = $this->sender->sent[0]['payload'];
        $this->assertSame('Nouveau shift assigné', $payload['title']);
        $this->assertStringContainsString('Alpha', $payload['body']);
        $this->assertSame('shift_assigned', $payload['type']);
        $this->assertSame('/my-shifts', $payload['link']);
        $this->assertSame('42', $payload['reference_id']);
        $this->assertSame('0', $payload['quiet']);
        $this->assertSame($user->notifications()->firstOrFail()->id, $payload['notification_id']);
    }

    public function test_an_external_link_is_never_put_in_the_payload(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);

        $user->notify(KintaiNotification::make('shift_assigned', 'x', [], 'https://evil.example/phish'));

        $this->assertSame('', $this->sender->sent[0]['payload']['link']);
    }

    public function test_the_payload_is_quiet_during_the_users_quiet_hours(): void
    {
        $user = User::factory()->create(['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->subscribe($user);

        Date::setTestNow(Carbon::parse('2026-11-10 23:30:00', 'UTC'));
        $user->notify($this->notification());
        Date::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $user->notify($this->notification());

        $this->assertSame('1', $this->sender->sent[0]['payload']['quiet']);
        $this->assertSame('0', $this->sender->sent[1]['payload']['quiet']);
        Date::setTestNow();
    }

    // Résultats d'envoi

    public function test_a_successful_send_refreshes_last_used_at(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $this->assertNull($subscription->last_used_at);

        $user->notify($this->notification());

        $this->assertNotNull($subscription->fresh()?->last_used_at);
    }

    public function test_an_expired_subscription_is_deleted_and_the_others_kept(): void
    {
        $user = User::factory()->create();
        $gone = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/gone');
        $kept = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/kept');
        $failing = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/failing');
        $this->sender->results[$gone->endpoint] = WebPushResult::Expired;
        $this->sender->results[$failing->endpoint] = WebPushResult::Failed;

        $user->notify($this->notification());

        $this->assertNull(WebPushSubscription::find($gone->id));
        $this->assertNotNull(WebPushSubscription::find($kept->id));
        $this->assertNotNull(WebPushSubscription::find($failing->id));
    }

    public function test_an_endpoint_that_no_longer_passes_the_check_is_not_contacted_nor_deleted(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        // Le nom se résout désormais vers une adresse interne (rebinding DNS).
        $this->app->instance(WebPushEndpointValidator::class, new WebPushEndpointValidator(fn (): array => ['10.0.0.5']));
        Log::spy();

        $user->notify($this->notification());

        $this->assertSame([], $this->sender->sent);
        $this->assertNotNull(WebPushSubscription::find($subscription->id));
    }

    // Isolation

    public function test_a_failing_sender_neither_throws_nor_blocks_the_bell(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->sender->throws = true;

        $user->notify($this->notification());

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, WebPushSubscription::count());
    }

    public function test_a_failing_web_push_does_not_prevent_the_fcm_channel(): void
    {
        config(['kintai.fcm.enabled' => true, 'kintai.fcm.project_id' => 'demo']);
        $user = User::factory()->create();
        $this->subscribe($user);
        $user->devicePushTokens()->create(['token' => 'tok', 'platform' => 'ios']);
        $this->sender->throws = true;

        $fcm = $this->createMock(FcmChannel::class);
        $fcm->expects($this->once())->method('send');
        $this->app->instance(FcmChannel::class, $fcm);

        $user->notify($this->notification());

        $this->assertSame(1, $user->notifications()->count());
    }
}
