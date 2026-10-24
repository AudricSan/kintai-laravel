<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\Calendar\GoogleCalendarClient;
use kintai\Core\Services\Calendar\GoogleCalendarException;
use kintai\Core\Services\Calendar\GoogleCalendarSettings;
use kintai\Core\Services\SecretBox;
use kintai\Tests\Support\FakeHttpTransport;
use kintai\Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

/** Client Google : OAuth (code + PKCE, rafraîchissement) et appels de l'API Agenda, sur un transport factice. */
final class GoogleCalendarClientTest extends TestCase
{
    private FakeHttpTransport $http;
    private GoogleCalendarClient $client;

    protected function setUp(): void
    {
        $settings = new InMemoryAppSettings();
        $config   = new GoogleCalendarSettings($settings, new SecretBox($settings));
        $config->save(true, 'client-id.apps.googleusercontent.com', 'client-secret');
        $this->http   = new FakeHttpTransport();
        $this->client = new GoogleCalendarClient($config, $this->http);
    }

    public function testAuthorizationUrlCarriesPkceMinimalScopeAndOfflineAccess(): void
    {
        $url = $this->client->authorizationUrl('https://kintai.test/calendar/google/callback', 'state-1', GoogleCalendarClient::codeChallenge('verifier'), 'a@b.c');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('client-id.apps.googleusercontent.com', $q['client_id']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(GoogleCalendarClient::codeChallenge('verifier'), $q['code_challenge']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('state-1', $q['state']);
        $this->assertStringContainsString('calendar.app.created', $q['scope']);
        $this->assertStringNotContainsString('auth/calendar ', $q['scope'] . ' ', 'jamais le périmètre complet de l’agenda');
        $this->assertSame('a@b.c', $q['login_hint']);
    }

    public function testCodeChallengeIsTheRfc7636Vector(): void
    {
        // Vecteur de l'annexe B de la RFC 7636.
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', GoogleCalendarClient::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testExchangeCodeReturnsRefreshTokenAndAccountEmail(): void
    {
        $idToken = 'h.' . rtrim(strtr(base64_encode(json_encode(['email' => 'moi@example.com'])), '+/', '-_'), '=') . '.s';
        $this->http->when('POST', '/token', ['status' => 200, 'body' => json_encode(['refresh_token' => 'R', 'access_token' => 'A', 'id_token' => $idToken])]);

        $r = $this->client->exchangeCode('code', 'verifier', 'https://kintai.test/cb');

        $this->assertSame(['refresh_token' => 'R', 'access_token' => 'A', 'email' => 'moi@example.com'], $r);
        parse_str($this->http->requests[0]['body'], $form);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('verifier', $form['code_verifier']);
        $this->assertSame('client-secret', $form['client_secret']);
    }

    public function testExchangeWithoutRefreshTokenIsAnError(): void
    {
        $this->http->when('POST', '/token', ['status' => 200, 'body' => json_encode(['access_token' => 'A'])]);

        $this->expectException(GoogleCalendarException::class);
        $this->client->exchangeCode('code', 'v', 'https://kintai.test/cb');
    }

    public function testInvalidGrantMeansTheLinkIsRevoked(): void
    {
        $this->http->when('POST', '/token', ['status' => 400, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'])]);

        try {
            $this->client->accessToken('R');
            $this->fail('exception attendue');
        } catch (GoogleCalendarException $e) {
            $this->assertTrue($e->isAuthRevoked());
            $this->assertFalse($e->isRetryable());
        }
    }

    public function testCalendarAndEventCalls(): void
    {
        $this->http->when('POST', '/calendars', ['status' => 200, 'body' => json_encode(['id' => 'cal-1'])]);
        $this->http->when('POST', '/events', ['status' => 200, 'body' => json_encode(['id' => 'evt-1'])]);

        $this->assertSame('cal-1', $this->client->createCalendar('tok', 'Sakura Co.', 'Asia/Tokyo'));
        $this->assertSame('evt-1', $this->client->insertEvent('tok', 'cal@group', ['summary' => 'x']));
        $this->client->patchEvent('tok', 'cal@group', 'evt-1', ['summary' => 'y']);
        $this->client->renameCalendar('tok', 'cal@group', 'Nouveau nom');

        $this->assertSame('Bearer tok', $this->http->requests[1]['headers']['Authorization']);
        $this->assertStringContainsString('/calendars/cal%40group/events', $this->http->requests[1]['url']);
        $this->assertSame('PATCH', $this->http->requests[2]['method']);
        $this->assertStringEndsWith('/events/evt-1', $this->http->requests[2]['url']);
        $this->assertSame(['summary' => 'Nouveau nom'], json_decode((string) $this->http->requests[3]['body'], true));
    }

    public function testDeletingAnAlreadyDeletedEventIsNotAnError(): void
    {
        $this->http->when('DELETE', '/events/gone', ['status' => 410, 'body' => '{"error":{"message":"Resource has been deleted"}}']);

        $this->client->deleteEvent('tok', 'cal', 'gone');

        $this->addToAssertionCount(1);
    }

    public function testErrorsAreClassified(): void
    {
        $this->http->when('POST', '/events', ['status' => 429, 'body' => '{}']);
        try {
            $this->client->insertEvent('tok', 'cal', []);
            $this->fail();
        } catch (GoogleCalendarException $e) {
            $this->assertTrue($e->isRetryable());
        }

        $this->http->when('PATCH', '/events/e', ['status' => 404, 'body' => '{}']);
        try {
            $this->client->patchEvent('tok', 'cal', 'e', []);
            $this->fail();
        } catch (GoogleCalendarException $e) {
            $this->assertTrue($e->isGone());
        }
    }

    public function testAnUnauthorizedApiCallCountsAsRevoked(): void
    {
        $this->http->when('POST', '/events', ['status' => 401, 'body' => '{"error":{"message":"Invalid Credentials"}}']);

        try {
            $this->client->insertEvent('tok', 'cal', []);
            $this->fail();
        } catch (GoogleCalendarException $e) {
            $this->assertTrue($e->isAuthRevoked());
        }
    }

    public function testSettingsKeepTheSecretWhenTheFormLeavesItEmptyAndNeverStoreItInClear(): void
    {
        $values   = new InMemoryAppSettings();
        $settings = new GoogleCalendarSettings($values, new SecretBox($values));

        $settings->save(true, 'id', 'secret-1');
        $settings->save(true, 'id-2', '');

        $this->assertSame('secret-1', $settings->clientSecret());
        $this->assertSame('id-2', $settings->clientId());
        $this->assertStringNotContainsString('secret-1', $values->get(GoogleCalendarSettings::CLIENT_SECRET));
        $this->assertTrue($settings->isAvailable());

        $settings->save(false, 'id-2', null);
        $this->assertFalse($settings->isAvailable());
    }
}
