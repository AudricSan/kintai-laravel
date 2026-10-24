<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;

/**
 * Envoie des notifications push via Firebase Cloud Messaging (API HTTP v1), qui
 * relaie vers Android, iOS (APNs) et le web push depuis un seul fournisseur — évite
 * d'intégrer APNs séparément. No-op silencieux tant que push.fcm.enabled n'est pas
 * activé en config (voir config/push.php) : ne doit jamais faire échouer l'action
 * métier (ex. validation d'un échange de shift) qui déclenche la notification.
 */
final class PushNotificationService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';

    private bool $enabled;
    private string $projectId;
    private string $credentialsPath;
    private ?array $credentials = null;
    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenExpiresAt = 0;

    /** @var (callable(string, array, string): array{status: int, body: string})|null Transport injectable pour les tests, remplace curl/stream_context. */
    private $transport;

    public function __construct(
        array $config,
        private readonly DevicePushTokenRepositoryInterface $tokens,
        ?callable $transport = null,
    ) {
        $this->enabled         = (bool) ($config['fcm']['enabled'] ?? false);
        $this->projectId       = (string) ($config['fcm']['project_id'] ?? '');
        $this->credentialsPath = (string) ($config['fcm']['credentials_path'] ?? '');
        $this->transport       = $transport;
    }

    /** @param array<string, string> $data Charge utile applicative (ex. type, reference_id) pour le deep-link côté app. */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        if (!$this->enabled || $this->projectId === '') {
            return;
        }

        // Les navigateurs ont leur propre canal (WebPushService) ; d'anciennes lignes « web » n'ont jamais fonctionné.
        $devices = array_filter($this->tokens->findByUser($userId), static fn(array $d) => ($d['platform'] ?? '') !== 'web');
        if ($devices === []) {
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return;
        }

        foreach ($devices as $device) {
            $this->sendToDevice($device, $accessToken, $title, $body, $data);
        }
    }

    /**
     * Message FCM v1 pour un appareil. Si $data['quiet'] vaut '1' (heures silencieuses de l'utilisateur), la notification
     * doit s'afficher sans son ni vibration : Android, priorité basse et ni son ni vibration par défaut, sur un canal
     * « kintai_quiet » que l'application mobile devra déclarer (sans canal de ce nom, Android prend le canal par défaut) ;
     * iOS, priorité 5 (aucun son n'est demandé).
     *
     * @param array<string, scalar> $data
     * @return array<string, mixed>
     */
    public static function buildMessage(string $token, string $title, string $body, array $data): array
    {
        $message = [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => array_map('strval', $data),
        ];
        if (($data['quiet'] ?? '') === '1') {
            $message['android'] = [
                'priority'     => 'normal',
                'notification' => [
                    'notification_priority'   => 'PRIORITY_LOW',
                    'default_sound'           => false,
                    'default_vibrate_timings' => false,
                    'channel_id'              => 'kintai_quiet',
                ],
            ];
            $message['apns'] = ['headers' => ['apns-priority' => '5']];
        }

        return $message;
    }

    private function sendToDevice(array $device, string $accessToken, string $title, string $body, array $data): void
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $payload = json_encode(['message' => self::buildMessage((string) $device['token'], $title, $body, $data)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->httpPost($url, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ], $payload);

        if ($response === null) {
            return;
        }

        if ($response['status'] === 200) {
            $this->tokens->touchLastUsed((int) $device['id']);
            return;
        }

        // FCM renvoie 404 (NOT_FOUND) ou un code d'erreur UNREGISTERED quand le jeton
        // n'est plus valide côté client (app désinstallée, jeton renouvelé) : le
        // conserver ne ferait qu'échouer indéfiniment à chaque notification future.
        $decoded = json_decode($response['body'], true);
        $status  = $decoded['error']['status'] ?? null;
        if ($response['status'] === 404 || $status === 'UNREGISTERED' || $status === 'NOT_FOUND') {
            $this->tokens->deleteByToken((string) $device['token']);
            return;
        }

        Log::warning('push_send_failed', [
            'user_id'     => $device['user_id'] ?? null,
            'http_status' => $response['status'],
            'fcm_status'  => $status,
        ]);
    }

    private function getAccessToken(): ?string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenExpiresAt) {
            return $this->cachedAccessToken;
        }

        $credentials = $this->loadCredentials();
        if ($credentials === null) {
            return null;
        }

        $jwt = $this->signJwt($credentials);
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $response = $this->httpPost(self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($response === null || $response['status'] !== 200) {
            Log::warning('push_token_exchange_failed', ['http_status' => $response['status'] ?? null]);
            return null;
        }

        $decoded = json_decode($response['body'], true);
        $token   = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        $this->cachedAccessToken           = $token;
        // Marge de 60s sous la durée annoncée par Google pour ne jamais utiliser un
        // jeton expiré à cause d'un léger décalage d'horloge/latence réseau.
        $this->cachedAccessTokenExpiresAt  = time() + (int) ($decoded['expires_in'] ?? 3600) - 60;

        return $token;
    }

    private function loadCredentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        if ($this->credentialsPath === '' || !is_file($this->credentialsPath)) {
            Log::warning('push_credentials_missing', ['path' => $this->credentialsPath]);
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->credentialsPath), true);
        if (!is_array($decoded) || !isset($decoded['client_email'], $decoded['private_key'])) {
            Log::warning('push_credentials_invalid', ['path' => $this->credentialsPath]);
            return null;
        }

        $this->credentials = $decoded;
        return $decoded;
    }

    /** Assertion JWT signée RS256 (compte de service Google) — pas de dépendance externe : openssl_sign suffit. */
    private function signJwt(array $credentials): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $segments = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

        openssl_sign($segments, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

        return $segments . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function httpPost(string $url, array $headers, string $body): ?array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $headers, $body);
        }

        if (function_exists('curl_init')) {
            return $this->httpPostViaCurl($url, $headers, $body);
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            Log::warning('push_http_no_method', ['url' => $url]);
            return null;
        }

        return $this->httpPostViaStreamWrapper($url, $headers, $body);
    }

    private function httpPostViaCurl(string $url, array $headers, string $body): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            Log::warning('push_curl_failed', ['url' => $url, 'curl_error' => $error]);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private function httpPostViaStreamWrapper(string $url, array $headers, string $body): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $error = error_get_last();
            Log::warning('push_stream_failed', ['url' => $url, 'php_error' => $error['message'] ?? 'raison inconnue']);
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
