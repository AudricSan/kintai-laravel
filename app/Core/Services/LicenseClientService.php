<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

/**
 * Client du serveur de licence distant (projet séparé "License Manager", API
 * consommée via sdk/php/LicenseClient.php côté serveur — voir son README).
 * N'effectue jamais d'appel réseau tant qu'aucune clé de licence n'est saisie
 * (`app_settings.license_key`) : le plan gratuit reste utilisable hors-ligne,
 * sans dépendance à ce service. `PlanLimitService::isPaidPlanActive()`
 * (lecture pure, sans réseau) est la seule méthode que le reste de l'app
 * consulte pour savoir si les limites du plan gratuit doivent être levées.
 */
final class LicenseClientService
{
    private const SETTING_LICENSE_KEY  = 'license_key';
    private const SETTING_INSTANCE_ID  = 'license_instance_id';
    private const SETTING_STATE        = 'license_state';

    /** @var (callable(string, array, string): ?string)|null Transport injectable pour les tests, remplace HttpFetcher. */
    private $transport;

    public function __construct(
        private readonly AppSettingsRepositoryInterface $appSettings,
        private readonly array $config,
        private readonly HttpFetcher $http = new HttpFetcher(),
        ?callable $transport = null,
        private readonly LicenseTokenVerifier $tokenVerifier = new LicenseTokenVerifier(null),
    ) {
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return $this->config['base_url'] !== '' && $this->config['api_key'] !== '';
    }

    public function licenseKey(): ?string
    {
        $key = $this->appSettings->get(self::SETTING_LICENSE_KEY);
        return $key !== null && $key !== '' ? $key : null;
    }

    /** Identifiant stable de cette instance, généré une seule fois au premier besoin. */
    public function instanceId(): string
    {
        $id = $this->appSettings->get(self::SETTING_INSTANCE_ID);
        if ($id !== null && $id !== '') {
            return $id;
        }

        $id = bin2hex(random_bytes(16));
        $this->appSettings->set(self::SETTING_INSTANCE_ID, $id);
        return $id;
    }

    /** État en cache (pour affichage sur /admin/license), ou null si aucune licence n'a jamais été activée. */
    public function state(): ?array
    {
        $raw = $this->appSettings->get(self::SETTING_STATE);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Le dernier contact serveur date de plus de check_interval_hours (ou aucun n'a jamais eu lieu). */
    public function isStale(): bool
    {
        $state = $this->state();
        $checkedAt = (int) ($state['checked_at'] ?? 0);
        if ($checkedAt === 0) {
            return true;
        }
        $intervalSeconds = max(1, (int) ($this->config['check_interval_hours'] ?? 24)) * 3600;
        return (time() - $checkedAt) >= $intervalSeconds;
    }

    /** Revalide auprès du serveur si le dernier contact est trop ancien — appelé au chargement de /admin/license pour ne pas dépendre uniquement du cron. No-op si aucune clé n'est enregistrée ou si l'état est encore frais. */
    public function refreshIfStale(): void
    {
        if ($this->licenseKey() !== null && $this->isStale()) {
            $this->refresh();
        }
    }

    /**
     * Lecture pure, sans réseau : le plan payant est considéré actif si le
     * dernier contact serveur a confirmé la licence, ou si l'instance est
     * encore dans la période de grâce hors-ligne suivant la dernière
     * confirmation (serveur injoignable lors des tentatives suivantes).
     */
    public function isPaidPlanActive(): bool
    {
        $state = $this->state();
        if ($state === null) {
            return false;
        }

        if (($state['status'] ?? null) === 'active') {
            return true;
        }

        if (($state['status'] ?? null) === 'degraded') {
            $lastValidAt = (int) ($state['last_valid_at'] ?? 0);
            $graceSeconds = max(0, (int) ($this->config['grace_period_days'] ?? 0)) * 86400;
            return $lastValidAt > 0 && (time() - $lastValidAt) < $graceSeconds;
        }

        return false;
    }

    /** Active une clé de licence sur cette instance (POST /activate). */
    public function activate(string $licenseKey): array
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return ['valid' => false, 'error' => 'missing_license_key'];
        }

        $this->appSettings->set(self::SETTING_LICENSE_KEY, $licenseKey);

        if (!$this->isConfigured()) {
            $this->writeState(['status' => 'inactive', 'checked_at' => time()]);
            return ['valid' => false, 'error' => 'server_not_configured'];
        }

        $result = $this->call('activate', [
            'license_key'    => $licenseKey,
            'instance_id'    => $this->instanceId(),
            'instance_label' => (string) (env('APP_URL', '') ?: php_uname('n')),
        ]);

        $this->applyResult($result);
        return $result;
    }

    /** Revalide la licence déjà activée (POST /validate) — utilisé par le cron et le bouton "Vérifier maintenant". No-op si aucune clé n'est enregistrée. */
    public function refresh(): ?array
    {
        $licenseKey = $this->licenseKey();
        if ($licenseKey === null || !$this->isConfigured()) {
            return null;
        }

        $result = $this->call('validate', [
            'license_key' => $licenseKey,
            'instance_id' => $this->instanceId(),
        ]);

        $this->applyResult($result);
        return $result;
    }

    /** Libère le siège occupé par cette instance et efface la licence locale. */
    public function deactivate(): void
    {
        $licenseKey = $this->licenseKey();
        if ($licenseKey !== null && $this->isConfigured()) {
            $this->call('deactivate', [
                'license_key' => $licenseKey,
                'instance_id' => $this->instanceId(),
            ]);
        }

        $this->appSettings->set(self::SETTING_LICENSE_KEY, '');
        $this->appSettings->set(self::SETTING_STATE, '');
    }

    /** @return array{valid: bool, error?: string, status?: string, type?: string, expires_at?: ?string} */
    private function call(string $endpoint, array $payload): array
    {
        $payload['api_key'] = $this->config['api_key'];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $url = $this->config['base_url'] . '/' . $endpoint;
        $headers = ['Content-Type: application/json'];

        $response = $this->transport !== null
            ? ($this->transport)($url, $headers, $body)
            : $this->http->post($url, $headers, $body, 10);

        if ($response === null) {
            return ['valid' => false, 'error' => 'server_unreachable'];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['valid' => false, 'error' => 'invalid_response'];
    }

    /** Traduit la réponse du serveur (ou son absence) en état local persisté. */
    private function applyResult(array $result): void
    {
        $now = time();
        $previous = $this->state();

        if (($result['error'] ?? null) === 'server_unreachable') {
            // Serveur injoignable : on garde le dernier état connu, en le marquant
            // "degraded" pour activer la logique de grâce dans isPaidPlanActive().
            $this->writeState([
                'status'             => ($previous['status'] ?? null) === 'active' || ($previous['status'] ?? null) === 'degraded' ? 'degraded' : 'inactive',
                'type'               => $previous['type'] ?? null,
                'issued_at'          => $previous['issued_at'] ?? null,
                'expires_at'         => $previous['expires_at'] ?? null,
                'max_activations'    => $previous['max_activations'] ?? null,
                'active_activations' => $previous['active_activations'] ?? null,
                'license_token'      => $previous['license_token'] ?? null,
                'last_valid_at'      => $previous['last_valid_at'] ?? null,
                'checked_at'         => $now,
            ]);
            return;
        }

        $valid = (bool) ($result['valid'] ?? false);
        $this->writeState([
            'status'             => $valid ? 'active' : 'inactive',
            'type'               => $result['type'] ?? null,
            'issued_at'          => $result['issued_at'] ?? ($previous['issued_at'] ?? null),
            'expires_at'         => $result['expires_at'] ?? null,
            'max_activations'    => $result['max_activations'] ?? ($previous['max_activations'] ?? null),
            'active_activations' => $result['active_activations'] ?? ($previous['active_activations'] ?? null),
            'license_token'      => $result['license_token'] ?? ($previous['license_token'] ?? null),
            'error'              => $valid ? null : ($result['error'] ?? null),
            'last_valid_at'      => $valid ? $now : ($previous['last_valid_at'] ?? null),
            'checked_at'         => $now,
        ]);
    }

    /**
     * Entitlements verifies cryptographiquement (type/palier/limites/expiration/
     * sieges), decodes depuis le license_token signe stocke localement — jamais
     * depuis les champs plats de state(), qu'une base self-hosted editee a la
     * main pourrait avoir falsifies. Null si aucun token n'est enregistre ou si
     * sa signature ne verifie pas (cle publique absente, token corrompu ou emis
     * par une autre cle) : l'appelant doit alors retomber sur le plan gratuit.
     */
    public function entitlements(): ?array
    {
        $token = $this->state()['license_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $this->tokenVerifier->verify($token);
    }

    private function writeState(array $state): void
    {
        $this->appSettings->set(self::SETTING_STATE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
