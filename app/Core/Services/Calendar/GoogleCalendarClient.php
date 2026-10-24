<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

/**
 * Client minimal de Google : OAuth 2.0 (code + PKCE, jeton de rafraîchissement) et les quatre appels de l'API Agenda
 * dont Kintai a besoin (créer/renommer un agenda, insérer/modifier/supprimer un événement). Pas de dépendance
 * `google/apiclient` : une dizaine de requêtes HTTP suffisent, et le transport reste remplaçable en test.
 *
 * Le périmètre `calendar.app.created` donne accès aux seuls agendas créés par Kintai, jamais au reste de l'agenda de
 * l'utilisateur. `openid email` ne sert qu'à afficher quel compte est lié.
 */
final class GoogleCalendarClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.app.created openid email';

    private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const API        = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly GoogleCalendarSettings $settings,
        private readonly HttpTransportInterface $http,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge, ?string $loginHint = null): string
    {
        $query = [
            'client_id'             => $this->settings->clientId(),
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => self::SCOPE,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            // offline + consent : sans cela Google ne redonne pas de jeton de rafraîchissement à une reconnexion.
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes' => 'false',
        ];
        if ($loginHint !== null && $loginHint !== '') {
            $query['login_hint'] = $loginHint;
        }
        return self::AUTH_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Défi PKCE (S256) du vérificateur. */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Échange le code d'autorisation contre un jeton de rafraîchissement.
     *
     * @return array{refresh_token: string, access_token: string, email: ?string}
     */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): array
    {
        $json = $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $redirectUri,
        ]);
        $refresh = (string) ($json['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new GoogleCalendarException('Google n’a pas fourni de jeton de rafraîchissement.', 200, 'no_refresh_token');
        }
        return [
            'refresh_token' => $refresh,
            'access_token'  => (string) ($json['access_token'] ?? ''),
            'email'         => self::emailFromIdToken((string) ($json['id_token'] ?? '')),
        ];
    }

    /**
     * Vérifie les identifiants du projet Google sans rien lier : un échange de code volontairement faux. Google répond
     * « invalid_client » quand l'identifiant ou le secret est faux, et une autre erreur (invalid_grant) quand ils sont
     * bons mais que le code, lui, ne l'est pas.
     *
     * @return string 'ok' | 'invalid_client' | 'unreachable'
     */
    public function checkCredentials(string $redirectUri): string
    {
        try {
            $this->tokenRequest([
                'grant_type' => 'authorization_code', 'code' => 'kintai-credentials-check',
                'code_verifier' => str_repeat('a', 43), 'redirect_uri' => $redirectUri,
            ]);
            return 'ok';
        } catch (GoogleCalendarException $e) {
            if ($e->httpStatus === 0) {
                return 'unreachable';
            }
            return in_array($e->reason, ['invalid_client', 'unauthorized_client'], true) ? 'invalid_client' : 'ok';
        }
    }

    public function accessToken(string $refreshToken): string
    {
        $json = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        $token = (string) ($json['access_token'] ?? '');
        if ($token === '') {
            throw new GoogleCalendarException('Réponse OAuth sans jeton d’accès.', 200, 'no_access_token');
        }
        return $token;
    }

    /** Révoque le jeton chez Google ; une panne n'empêche pas la déconnexion locale. */
    public function revoke(string $token): bool
    {
        $r = $this->http->request('POST', self::REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $token]));
        return $r['status'] === 200;
    }

    /** @return string identifiant du nouvel agenda */
    public function createCalendar(string $accessToken, string $name, string $timeZone): string
    {
        $json = $this->api('POST', '/calendars', $accessToken, ['summary' => $name, 'timeZone' => $timeZone]);
        return (string) ($json['id'] ?? '');
    }

    public function renameCalendar(string $accessToken, string $calendarId, string $name): void
    {
        $this->api('PATCH', '/calendars/' . rawurlencode($calendarId), $accessToken, ['summary' => $name]);
    }

    /**
     * @param array<string, mixed> $event
     * @return string identifiant de l'événement créé
     */
    public function insertEvent(string $accessToken, string $calendarId, array $event): string
    {
        $json = $this->api('POST', '/calendars/' . rawurlencode($calendarId) . '/events', $accessToken, $event);
        return (string) ($json['id'] ?? '');
    }

    /** @param array<string, mixed> $event */
    public function patchEvent(string $accessToken, string $calendarId, string $eventId, array $event): void
    {
        $this->api('PATCH', '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId), $accessToken, $event);
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        try {
            $this->api('DELETE', '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId), $accessToken);
        } catch (GoogleCalendarException $e) {
            // Déjà supprimé côté Google : le résultat voulu est atteint.
            if (!$e->isGone()) {
                throw $e;
            }
        }
    }

    /** @param array<string, string> $form @return array<string, mixed> */
    private function tokenRequest(array $form): array
    {
        $form += ['client_id' => $this->settings->clientId(), 'client_secret' => $this->settings->clientSecret()];
        $r = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($form));
        $json = json_decode($r['body'], true);
        $json = is_array($json) ? $json : [];
        if ($r['status'] !== 200) {
            $reason = (string) ($json['error'] ?? '');
            throw new GoogleCalendarException('OAuth Google : ' . ($json['error_description'] ?? ($reason !== '' ? $reason : 'erreur ' . $r['status'])), $r['status'], $reason);
        }
        return $json;
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function api(string $method, string $path, string $accessToken, ?array $payload = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $r = $this->http->request($method, self::API . $path, $headers, $body);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $json = json_decode($r['body'], true);
            return is_array($json) ? $json : [];
        }
        $json   = json_decode($r['body'], true);
        $error  = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $reason = (string) ($error['errors'][0]['reason'] ?? '');
        if ($r['status'] === 401) {
            $reason = 'invalid_grant';
        }
        throw new GoogleCalendarException('API Agenda : ' . ($error['message'] ?? 'erreur ' . $r['status']), $r['status'], $reason);
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
