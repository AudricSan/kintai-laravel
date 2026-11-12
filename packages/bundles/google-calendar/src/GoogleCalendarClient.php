<?php

namespace Kintai\Bundles\GoogleCalendar;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client minimal de Google : OAuth 2.0 (code + PKCE, jeton de rafraîchissement) et les appels de l'API Agenda dont
 * Kintai a besoin (créer et renommer un agenda, insérer, modifier et supprimer un événement), par le client HTTP de
 * Laravel (`Http::fake()` en test). Pas de dépendance `google/apiclient`.
 *
 * Le périmètre `calendar.app.created` donne accès aux seuls agendas créés par Kintai, jamais au reste de l'agenda de
 * l'utilisateur. `openid email` ne sert qu'à afficher quel compte est lié.
 */
class GoogleCalendarClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.app.created openid email';

    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const API = 'https://www.googleapis.com/calendar/v3';

    private const TIMEOUT = 20;

    public function __construct(private readonly GoogleCalendarSettings $settings) {}

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge, ?string $loginHint = null): string
    {
        $query = [
            'client_id' => $this->settings->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            // offline + consent : sans cela Google ne redonne pas de jeton de rafraîchissement à une reconnexion.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
        ];

        if ($loginHint !== null && $loginHint !== '') {
            $query['login_hint'] = $loginHint;
        }

        return self::AUTH_URL.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Défi PKCE (S256) du vérificateur.
     */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Échange le code d'autorisation contre un jeton de rafraîchissement.
     *
     * @return array{refresh_token: string, access_token: string, email: string|null}
     *
     * @throws GoogleCalendarException
     */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): array
    {
        $json = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ]);

        $refresh = (string) ($json['refresh_token'] ?? '');

        if ($refresh === '') {
            throw new GoogleCalendarException('Google did not provide a refresh token.', 200, 'no_refresh_token');
        }

        return [
            'refresh_token' => $refresh,
            'access_token' => (string) ($json['access_token'] ?? ''),
            'email' => self::emailFromIdToken((string) ($json['id_token'] ?? '')),
        ];
    }

    /**
     * Vérifie les identifiants du projet Google sans rien lier : un échange de code volontairement faux. Google répond
     * « invalid_client » quand l'identifiant ou le secret est faux, et une autre erreur (invalid_grant) quand ils sont
     * bons mais que le code, lui, ne l'est pas.
     *
     * @return 'ok'|'invalid_client'|'unreachable'
     */
    public function checkCredentials(string $redirectUri): string
    {
        try {
            $this->tokenRequest([
                'grant_type' => 'authorization_code',
                'code' => 'kintai-credentials-check',
                'code_verifier' => str_repeat('a', 43),
                'redirect_uri' => $redirectUri,
            ]);

            return 'ok';
        } catch (GoogleCalendarException $e) {
            if ($e->httpStatus === 0) {
                return 'unreachable';
            }

            return in_array($e->reason, ['invalid_client', 'unauthorized_client'], true) ? 'invalid_client' : 'ok';
        }
    }

    /**
     * @throws GoogleCalendarException
     */
    public function accessToken(string $refreshToken): string
    {
        $json = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        $token = (string) ($json['access_token'] ?? '');

        if ($token === '') {
            throw new GoogleCalendarException('OAuth response without an access token.', 200, 'no_access_token');
        }

        return $token;
    }

    /**
     * Révoque le jeton chez Google ; une panne n'empêche pas la déconnexion locale.
     */
    public function revoke(string $token): bool
    {
        try {
            return Http::asForm()->timeout(5)->post(self::REVOKE_URL, ['token' => $token])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @return string Identifiant du nouvel agenda.
     *
     * @throws GoogleCalendarException
     */
    public function createCalendar(string $accessToken, string $name, string $timeZone): string
    {
        $json = $this->api('POST', '/calendars', $accessToken, ['summary' => $name, 'timeZone' => $timeZone]);

        return (string) ($json['id'] ?? '');
    }

    /**
     * @throws GoogleCalendarException
     */
    public function renameCalendar(string $accessToken, string $calendarId, string $name): void
    {
        $this->api('PATCH', '/calendars/'.rawurlencode($calendarId), $accessToken, ['summary' => $name]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return string Identifiant de l'événement créé.
     *
     * @throws GoogleCalendarException
     */
    public function insertEvent(string $accessToken, string $calendarId, array $event): string
    {
        $json = $this->api('POST', '/calendars/'.rawurlencode($calendarId).'/events', $accessToken, $event);

        return (string) ($json['id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws GoogleCalendarException
     */
    public function patchEvent(string $accessToken, string $calendarId, string $eventId, array $event): void
    {
        $this->api('PATCH', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), $accessToken, $event);
    }

    /**
     * Supprime l'événement ; déjà supprimé côté Google, le résultat voulu est atteint.
     *
     * @throws GoogleCalendarException
     */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        try {
            $this->api('DELETE', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), $accessToken);
        } catch (GoogleCalendarException $e) {
            if (! $e->isGone()) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     *
     * @throws GoogleCalendarException
     */
    private function tokenRequest(array $form): array
    {
        $form += ['client_id' => $this->settings->clientId(), 'client_secret' => $this->settings->clientSecret()];

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(self::TOKEN_URL, $form);
        } catch (ConnectionException) {
            throw new GoogleCalendarException('Google cannot be reached.');
        }

        if (! $response->successful()) {
            $reason = (string) $response->json('error', '');
            $detail = (string) $response->json('error_description', $reason !== '' ? $reason : 'error '.$response->status());

            throw new GoogleCalendarException('Google OAuth: '.$detail, $response->status(), $reason);
        }

        return self::decode($response);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws GoogleCalendarException
     */
    private function api(string $method, string $path, string $accessToken, ?array $payload = null): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(self::TIMEOUT)
                ->send($method, self::API.$path, $payload === null ? [] : ['json' => $payload]);
        } catch (ConnectionException) {
            throw new GoogleCalendarException('Google cannot be reached.');
        }

        if ($response->successful()) {
            return self::decode($response);
        }

        $reason = (string) $response->json('error.errors.0.reason', '');

        if ($response->status() === 401) {
            $reason = 'invalid_grant';
        }

        throw new GoogleCalendarException('Calendar API: '.(string) $response->json('error.message', 'error '.$response->status()), $response->status(), $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private static function emailFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            return null;
        }

        // Reçu en direct de Google par TLS lors de l'échange du code : on lit l'adresse pour l'affichage seulement.
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);
        $email = is_array($payload) ? (string) ($payload['email'] ?? '') : '';

        return $email !== '' ? $email : null;
    }
}
