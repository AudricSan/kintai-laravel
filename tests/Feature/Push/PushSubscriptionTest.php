<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use FakesPush;
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureVapid();
        $this->fakeWebPushSender();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $endpoint = self::ENDPOINT): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']];
    }

    public function test_guests_cannot_use_the_routes(): void
    {
        $this->postJson(route('push.subscriptions.store'), $this->body())->assertUnauthorized();
        $this->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
        $this->getJson(route('push.status'))->assertUnauthorized();
    }

    public function test_a_subscription_is_stored_and_refreshed_without_duplicates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('User-Agent', 'TestBrowser/1.0')->postJson(route('push.subscriptions.store'), $this->body())->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $this->body())->assertOk();

        $this->assertSame(1, WebPushSubscription::count());
        $subscription = WebPushSubscription::firstOrFail();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame(hash('sha256', self::ENDPOINT), $subscription->endpoint_hash);
        $this->assertNotNull($subscription->last_used_at);
    }

    public function test_a_subscription_already_registered_moves_to_the_current_account(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->subscribe($first, self::ENDPOINT);

        $this->actingAs($second)->postJson(route('push.subscriptions.store'), $this->body())->assertOk();

        $this->assertSame(1, WebPushSubscription::count());
        $this->assertSame($second->id, WebPushSubscription::firstOrFail()->user_id);
    }

    public function test_an_unsafe_endpoint_or_malformed_keys_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $this->body('http://fcm.googleapis.com/x'))->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $this->body('https://127.0.0.1/x'))->assertUnprocessable();
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => '<script>', 'auth' => 'ok']])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), ['endpoint' => self::ENDPOINT])->assertUnprocessable();

        $this->assertSame(0, WebPushSubscription::count());
    }

    public function test_registering_is_unavailable_without_vapid_keys(): void
    {
        config(['kintai.webpush.public_key' => null, 'kintai.webpush.private_key' => null]);

        $this->actingAs(User::factory()->create())->postJson(route('push.subscriptions.store'), $this->body())->assertNotFound();
        $this->assertSame(0, WebPushSubscription::count());
    }

    public function test_a_user_removes_only_their_own_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->subscribe($owner, self::ENDPOINT);

        $this->actingAs($other)->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(1, WebPushSubscription::count());

        $this->actingAs($owner)->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, WebPushSubscription::count());
    }

    public function test_status_reports_availability_and_whether_this_browser_is_subscribed_for_the_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->subscribe($owner, self::ENDPOINT);

        $this->actingAs($owner)->getJson(route('push.status', ['endpoint' => self::ENDPOINT]))
            ->assertOk()->assertJson(['available' => true, 'public_key' => 'BPublicKeyForTests', 'subscribed' => true]);
        $this->actingAs($owner)->getJson(route('push.status', ['endpoint' => 'https://fcm.googleapis.com/other']))
            ->assertJson(['subscribed' => false]);
        $this->actingAs($other)->getJson(route('push.status', ['endpoint' => self::ENDPOINT]))
            ->assertJson(['subscribed' => false]);
        $this->actingAs($other)->getJson(route('push.status'))->assertJson(['subscribed' => false]);
    }

    public function test_status_without_keys_is_unavailable(): void
    {
        config(['kintai.webpush.public_key' => null, 'kintai.webpush.private_key' => null]);

        $this->actingAs(User::factory()->create())->getJson(route('push.status'))->assertOk()->assertJson(['available' => false, 'public_key' => null]);
    }

    public function test_logging_out_removes_this_browsers_subscription_of_that_account_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->subscribe($user, self::ENDPOINT);
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/second-device');
        $this->subscribe($other, 'https://fcm.googleapis.com/fcm/send/not-mine');

        $this->actingAs($user)->post(route('logout'), ['push_endpoint' => self::ENDPOINT])->assertRedirect();

        $this->assertGuest();
        $this->assertSame(
            ['https://fcm.googleapis.com/fcm/send/not-mine', 'https://fcm.googleapis.com/fcm/send/second-device'],
            WebPushSubscription::query()->orderBy('endpoint')->pluck('endpoint')->all(),
        );
    }

    public function test_logging_out_without_an_endpoint_keeps_subscriptions(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);

        $this->actingAs($user)->post(route('logout'))->assertRedirect();

        $this->assertSame(1, WebPushSubscription::count());
    }

    public function test_logging_out_with_the_endpoint_of_another_account_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->subscribe($other, self::ENDPOINT);

        $this->actingAs($user)->post(route('logout'), ['push_endpoint' => self::ENDPOINT])->assertRedirect();

        $this->assertSame(1, WebPushSubscription::count());
    }
}
