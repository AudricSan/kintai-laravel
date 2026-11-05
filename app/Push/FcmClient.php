<?php

namespace App\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client FCM (API HTTP v1) : jeton OAuth obtenu par assertion JWT signée RS256 du compte de service Google
 * (openssl, sans dépendance), gardé en cache jusqu'à son expiration.
 */
class FcmClient
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'kintai.fcm.access_token';

    public function isEnabled(): bool
    {
        return (bool) config('kintai.fcm.enabled') && $this->projectId() !== '';
    }

    public function projectId(): string
    {
        return trim((string) config('kintai.fcm.project_id'));
    }

    /**
     * Message FCM v1 pour un appareil. Pendant les heures silencieuses (`quiet` = "1"), la notification s'affiche
     * sans son ni vibration : Android, priorité basse sur un canal « kintai_quiet » que l'application mobile doit
     * déclarer (sans canal de ce nom, Android prend le canal par défaut) ; iOS, priorité 5 (aucun son demandé).
     *
     * @return array<string, mixed>
     */
    public static function buildMessage(string $token, PushPayload $payload): array
    {
        $message = [
            'token' => $token,
            'notification' => ['title' => $payload->title, 'body' => $payload->body],
            'data' => $payload->data,
        ];

        if ($payload->isQuiet()) {
            $message['android'] = [
                'priority' => 'normal',
                'notification' => [
                    'notification_priority' => 'PRIORITY_LOW',
                    'default_sound' => false,
                    'default_vibrate_timings' => false,
                    'channel_id' => 'kintai_quiet',
                ],
            ];
            $message['apns'] = ['headers' => ['apns-priority' => '5']];
        }

        return $message;
    }

    /**
     * Envoie le message à un jeton.
     *
     * @return 'sent'|'unregistered'|'failed'
     */
    public function send(string $token, PushPayload $payload): string
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(5)
            ->acceptJson()
            ->post('https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send', [
                'message' => self::buildMessage($token, $payload),
            ]);

        if ($response->successful()) {
            return 'sent';
        }

        // 404 (NOT_FOUND) ou UNREGISTERED : le jeton n'est plus valide (application désinstallée ou jeton renouvelé).
        $status = $response->json('error.status');

        if ($response->status() === 404 || $status === 'UNREGISTERED' || $status === 'NOT_FOUND') {
            return 'unregistered';
        }

        Log::warning('FCM send failed.', ['http_status' => $response->status(), 'fcm_status' => $status]);

        return 'failed';
    }

    /**
     * Le fichier du compte de service est-il présent et exploitable (JSON avec adresse et clé privée) ?
     */
    public function hasReadableCredentials(): bool
    {
        try {
            $this->credentials();
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @throws RuntimeException Compte de service illisible ou échange refusé.
     */
    public function accessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(5)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion($this->credentials()),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('FCM token exchange failed (HTTP '.$response->status().').');
        }

        // Marge de 60 s sous la durée annoncée : jamais de jeton expiré à cause d'un léger décalage d'horloge.
        $lifetime = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put(self::CACHE_KEY, $token, $lifetime);

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $configured = trim((string) config('kintai.fcm.credentials_path'));
        $path = $configured === '' || preg_match('#^([A-Za-z]:[\\\\/]|/)#', $configured) === 1 ? $configured : storage_path($configured);

        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('FCM service account file not found.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! is_string($decoded['client_email'] ?? null) || ! is_string($decoded['private_key'] ?? null)) {
            throw new RuntimeException('FCM service account file is invalid.');
        }

        return ['client_email' => $decoded['client_email'], 'private_key' => $decoded['private_key']];
    }

    /**
     * Assertion JWT signée RS256 du compte de service.
     *
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function assertion(array $credentials): string
    {
        $now = time();
        $segments = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'.self::base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

        if (! openssl_sign($segments, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM assertion could not be signed.');
        }

        return $segments.'.'.self::base64Url($signature);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
