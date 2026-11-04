<?php

namespace Tests\Feature\Push;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\KintaiNotification;
use App\Push\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmChannelTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send';

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->credentialsPath = $this->writeCredentials();
        config([
            'kintai.fcm.enabled' => true,
            'kintai.fcm.project_id' => 'demo-project',
            'kintai.fcm.credentials_path' => $this->credentialsPath,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->credentialsPath)) {
            unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    private function writeCredentials(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            // Installations PHP sans openssl.cnf (Windows) : configuration minimale du dépôt.
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => base_path('config/openssl.cnf')]);
        }

        $this->assertNotFalse($key, 'openssl could not create a test key.');
        openssl_pkey_export($key, $pem, null, ['config' => base_path('config/openssl.cnf')]);

        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['client_email' => 'svc@demo-project.iam.gserviceaccount.com', 'private_key' => $pem]));

        return $path;
    }

    private function user(string $locale = 'fr'): User
    {
        $user = User::factory()->create(['locale' => $locale]);
        $user->devicePushTokens()->create(['token' => 'token-a', 'platform' => 'android']);

        return $user;
    }

    private function notification(): KintaiNotification
    {
        return KintaiNotification::make('shift_updated', 'A shift was added for you on :date from :start to :end (:store).', [
            'date' => '2026-11-10', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha',
        ], '/my-shifts', 7);
    }

    /**
     * @param  array<string, mixed>  $send
     */
    private function fakeGoogle(array $send = ['name' => 'projects/demo-project/messages/1'], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($send, $status),
        ]);
    }

    public function test_a_message_is_sent_with_a_bearer_token_and_the_common_payload(): void
    {
        $this->fakeGoogle();
        $user = $this->user();

        $user->notify($this->notification());

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::SEND_URL) {
                return false;
            }

            $message = $request->data()['message'];

            return $request->hasHeader('Authorization', 'Bearer ya29.test')
                && $message['token'] === 'token-a'
                && $message['notification']['title'] === 'Shift modifié'
                && $message['data']['type'] === 'shift_updated'
                && $message['data']['link'] === '/my-shifts'
                && $message['data']['reference_id'] === '7'
                && $message['data']['quiet'] === '0'
                && ! isset($message['android']);
        });
        $this->assertNotNull(DevicePushToken::firstOrFail()->last_used_at);
    }

    public function test_the_oauth_assertion_is_a_valid_rs256_jwt_of_the_service_account(): void
    {
        $this->fakeGoogle();

        $this->user()->notify($this->notification());

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== FcmClient::TOKEN_URL) {
                return false;
            }

            parse_str($request->body(), $form);
            [$header, $claims, $signature] = explode('.', $form['assertion']);
            $decode = fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
            $credentials = json_decode((string) file_get_contents($this->credentialsPath), true);
            $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($credentials['private_key']))['key'];

            return $form['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && json_decode($decode($header), true) === ['alg' => 'RS256', 'typ' => 'JWT']
                && json_decode($decode($claims), true)['iss'] === 'svc@demo-project.iam.gserviceaccount.com'
                && json_decode($decode($claims), true)['aud'] === FcmClient::TOKEN_URL
                && openssl_verify($header.'.'.$claims, $decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_the_access_token_is_cached_between_sends(): void
    {
        $this->fakeGoogle();
        $user = $this->user();

        $user->notify($this->notification());
        $user->notify($this->notification());
        app(FcmClient::class)->accessToken();

        Http::assertSentCount(3);
        $tokenRequests = Http::recorded(fn (Request $request): bool => $request->url() === FcmClient::TOKEN_URL);
        $this->assertCount(1, $tokenRequests);
        $this->assertSame('ya29.test', Cache::get('kintai.fcm.access_token'));
    }

    public function test_an_unregistered_token_is_deleted(): void
    {
        $this->fakeGoogle(['error' => ['code' => 404, 'status' => 'UNREGISTERED']], 404);
        $user = $this->user();

        $user->notify($this->notification());

        $this->assertSame(0, DevicePushToken::count());
    }

    public function test_a_not_found_status_also_deletes_the_token(): void
    {
        $this->fakeGoogle(['error' => ['code' => 400, 'status' => 'NOT_FOUND']], 400);

        $this->user()->notify($this->notification());

        $this->assertSame(0, DevicePushToken::count());
    }

    public function test_another_error_keeps_the_token(): void
    {
        $this->fakeGoogle(['error' => ['code' => 500, 'status' => 'INTERNAL']], 500);
        $user = $this->user();

        $user->notify($this->notification());

        $this->assertSame(1, DevicePushToken::count());
        $this->assertNull(DevicePushToken::firstOrFail()->last_used_at);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_quiet_hours_lower_the_priority_on_android_and_ios(): void
    {
        $this->fakeGoogle();
        $user = $this->user();
        $user->forceFill(['quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59'])->save();

        $user->notify($this->notification());

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['message'] ?? [];

            return $request->url() === self::SEND_URL
                && ($message['data']['quiet'] ?? null) === '1'
                && $message['android']['notification']['channel_id'] === 'kintai_quiet'
                && $message['apns']['headers']['apns-priority'] === '5';
        });
    }

    public function test_a_token_exchange_failure_is_isolated(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $user = $this->user();

        $user->notify($this->notification());

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, DevicePushToken::count());
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::SEND_URL);
    }

    public function test_missing_credentials_are_isolated(): void
    {
        $this->fakeGoogle();
        config(['kintai.fcm.credentials_path' => '/nonexistent/service-account.json']);
        $user = $this->user();

        $user->notify($this->notification());

        $this->assertSame(1, $user->notifications()->count());
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_fcm_is_disabled(): void
    {
        $this->fakeGoogle();
        config(['kintai.fcm.enabled' => false]);
        $user = $this->user();

        $user->notify($this->notification());
        (new FcmChannel(app(FcmClient::class)))->send($user, $this->notification());

        Http::assertNothingSent();
    }
}
