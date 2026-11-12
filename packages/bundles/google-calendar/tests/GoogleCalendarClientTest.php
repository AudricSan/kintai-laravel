<?php

namespace Kintai\Bundles\GoogleCalendar\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarClient;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarException;
use Tests\TestCase;

require_once __DIR__.'/GoogleCalendarFixtures.php';

/**
 * Client Google : OAuth (URL d'autorisation PKCE, échange du code, jeton de rafraîchissement, révocation), appels de
 * l'API Agenda, classement des erreurs (révocation, ressource disparue, panne passagère). Aucun appel réel : `Http::fake`.
 */
class GoogleCalendarClientTest extends TestCase
{
    use GoogleCalendarFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootGoogleCalendar();
        $this->configureGoogle();
    }

    private function client(): GoogleCalendarClient
    {
        return app(GoogleCalendarClient::class);
    }

    private function failure(callable $call): GoogleCalendarException
    {
        try {
            $call();
        } catch (GoogleCalendarException $e) {
            return $e;
        }

        $this->fail('A GoogleCalendarException was expected.');
    }

    public function test_the_authorization_url_carries_pkce_offline_access_and_the_minimal_scope(): void
    {
        $url = $this->client()->authorizationUrl('https://kintai.test/google-calendar/callback', 'state-1', GoogleCalendarClient::codeChallenge('verifier-1'), 'ana@example.test');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('client-id-123', $query['client_id']);
        $this->assertSame('https://kintai.test/google-calendar/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('state-1', $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', 'verifier-1', true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('ana@example.test', $query['login_hint']);
        $this->assertSame('https://www.googleapis.com/auth/calendar.app.created openid email', $query['scope']);
        $this->assertStringNotContainsString('client-secret-xyz', $url);
    }

    public function test_exchanging_the_code_returns_the_refresh_token_and_the_account_email(): void
    {
        $idToken = 'x.'.rtrim(strtr(base64_encode((string) json_encode(['email' => 'ana@example.test'])), '+/', '-_'), '=').'.y';
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['refresh_token' => 'refresh-1', 'access_token' => 'access-1', 'id_token' => $idToken])]);

        $tokens = $this->client()->exchangeCode('code-1', 'verifier-1', 'https://kintai.test/cb');

        $this->assertSame(['refresh_token' => 'refresh-1', 'access_token' => 'access-1', 'email' => 'ana@example.test'], $tokens);
        $this->sent('POST', 'oauth2.googleapis.com/token')->each(function (Request $request): void {
            $this->assertSame('authorization_code', $request['grant_type']);
            $this->assertSame('code-1', $request['code']);
            $this->assertSame('verifier-1', $request['code_verifier']);
            $this->assertSame('client-id-123', $request['client_id']);
            $this->assertSame('client-secret-xyz', $request['client_secret']);
        });
    }

    public function test_an_answer_without_refresh_token_is_refused(): void
    {
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1'])]);

        $this->assertSame('no_refresh_token', $this->failure(fn () => $this->client()->exchangeCode('c', 'v', 'https://kintai.test/cb'))->reason);
    }

    public function test_the_refresh_token_is_exchanged_for_an_access_token(): void
    {
        $this->fakeGoogle();

        $this->assertSame('access-token-1', $this->client()->accessToken('refresh-1'));
        $this->assertSame('refresh_token', $this->sent('POST', 'oauth2.googleapis.com/token')->first()['grant_type']);
        $this->assertSame('refresh-1', $this->sent('POST', 'oauth2.googleapis.com/token')->first()['refresh_token']);
    }

    public function test_an_invalid_grant_means_the_link_is_revoked_and_is_not_retried(): void
    {
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        $e = $this->failure(fn () => $this->client()->accessToken('refresh-1'));

        $this->assertTrue($e->isAuthRevoked());
        $this->assertFalse($e->isRetryable());
        $this->assertStringNotContainsString('refresh-1', $e->getMessage());
    }

    public function test_a_401_from_the_api_means_the_link_is_revoked(): void
    {
        $this->fakeGoogle(['POST /events' => Http::response(['error' => ['message' => 'Invalid Credentials']], 401)]);

        $this->assertTrue($this->failure(fn () => $this->client()->insertEvent('access', 'cal-1', ['summary' => 'x']))->isAuthRevoked());
    }

    public function test_rate_limits_and_server_errors_are_retryable(): void
    {
        foreach ([429, 500, 503] as $status) {
            $this->fakeGoogle(['POST /events' => Http::response(['error' => ['message' => 'Backend Error']], $status)]);

            $e = $this->failure(fn () => $this->client()->insertEvent('access', 'cal-1', ['summary' => 'x']));

            $this->assertTrue($e->isRetryable(), "status {$status}");
            $this->assertFalse($e->isAuthRevoked());
        }

        $this->fakeGoogle(['POST /events' => Http::response(['error' => ['message' => 'Rate Limit Exceeded', 'errors' => [['reason' => 'userRateLimitExceeded']]]], 403)]);
        $this->assertTrue($this->failure(fn () => $this->client()->insertEvent('access', 'cal-1', ['summary' => 'x']))->isRetryable());
    }

    public function test_a_plain_refusal_is_neither_retryable_nor_a_revocation(): void
    {
        $this->fakeGoogle(['POST /events' => Http::response(['error' => ['message' => 'Bad Request']], 400)]);

        $e = $this->failure(fn () => $this->client()->insertEvent('access', 'cal-1', ['summary' => 'x']));

        $this->assertFalse($e->isRetryable());
        $this->assertFalse($e->isAuthRevoked());
        $this->assertFalse($e->isGone());
    }

    public function test_a_network_failure_is_retryable(): void
    {
        $this->fakeNetworkDown();

        $this->assertTrue($this->failure(fn () => $this->client()->accessToken('refresh-1'))->isRetryable());
        $this->assertTrue($this->failure(fn () => $this->client()->insertEvent('access', 'cal-1', []))->isRetryable());
    }

    public function test_events_are_created_patched_and_deleted_in_the_given_calendar_with_the_bearer_token(): void
    {
        $this->fakeGoogle();

        $this->assertSame('cal-new', $this->client()->createCalendar('access-1', 'Kintai', 'Asia/Tokyo'));
        $this->assertStringStartsWith('evt-', $this->client()->insertEvent('access-1', 'cal/1', ['summary' => 'Shift']));
        $this->client()->patchEvent('access-1', 'cal/1', 'evt 1', ['summary' => 'Changed']);
        $this->client()->renameCalendar('access-1', 'cal/1', 'Renamed');
        $this->client()->deleteEvent('access-1', 'cal/1', 'evt 1');

        $this->assertSame(['summary' => 'Kintai', 'timeZone' => 'Asia/Tokyo'], $this->sent('POST', '/calendars')->first()->data());
        $this->assertSame('https://www.googleapis.com/calendar/v3/calendars/cal%2F1/events', $this->sent('POST', '/events')->first()->url());
        $this->assertSame('https://www.googleapis.com/calendar/v3/calendars/cal%2F1/events/evt%201', $this->sent('PATCH', '/events/')->first()->url());
        $this->assertSame(['summary' => 'Renamed'], $this->sent('PATCH', '/calendars/cal%2F1')->last()->data());
        $this->assertCount(1, $this->sent('DELETE', '/events/evt%201'));
        $this->assertSame('Bearer access-1', $this->sent('DELETE', '/events/')->first()->header('Authorization')[0]);
    }

    public function test_deleting_an_event_already_gone_is_a_success(): void
    {
        $this->fakeGoogle(['DELETE /events/' => Http::response(['error' => ['message' => 'Not Found']], 404)]);

        $this->client()->deleteEvent('access-1', 'cal-1', 'evt-1');

        $this->assertCount(1, $this->sent('DELETE', '/events/'));
    }

    public function test_a_missing_event_is_reported_as_gone_when_patched(): void
    {
        $this->fakeGoogle(['PATCH /events/' => Http::response(['error' => ['message' => 'Gone']], 410)]);

        $this->assertTrue($this->failure(fn () => $this->client()->patchEvent('access-1', 'cal-1', 'evt-1', []))->isGone());
    }

    public function test_revoking_reports_the_outcome_without_throwing(): void
    {
        $this->fakeGoogle();
        $this->assertTrue($this->client()->revoke('refresh-1'));
        $this->assertSame('refresh-1', $this->sent('POST', 'oauth2.googleapis.com/revoke')->first()['token']);

        $this->fakeGoogle(['POST oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400)]);
        $this->assertFalse($this->client()->revoke('refresh-1'));

        $this->fakeNetworkDown();
        $this->assertFalse($this->client()->revoke('refresh-1'));
    }

    public function test_the_credentials_check_tells_a_bad_client_from_a_good_one(): void
    {
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client'], 401)]);
        $this->assertSame('invalid_client', $this->client()->checkCredentials('https://kintai.test/cb'));

        // Identifiants bons, code faux : Google répond invalid_grant.
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->assertSame('ok', $this->client()->checkCredentials('https://kintai.test/cb'));

        $this->fakeNetworkDown();
        $this->assertSame('unreachable', $this->client()->checkCredentials('https://kintai.test/cb'));
    }
}
