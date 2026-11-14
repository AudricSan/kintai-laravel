<?php

namespace App\License;

use App\Settings\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Appels au LicenseManager : `/register`, `/activate`, `/validate` et `/deactivate` (POST JSON, clé produit en jeton Bearer (Sanctum côté Manager)). Ne lève
 * jamais : toute panne de réseau, réponse 5xx, 429 ou JSON illisible devient un résultat `unreachable`.
 */
class LicenseClient
{
    /** Longueur maximale d'un code d'erreur retenu. */
    private const ERROR_MAX = 64;

    public function __construct(private readonly AppSettings $settings) {}

    public function activate(string $licenseKey, string $instanceId, ?string $label = null): LicenseResult
    {
        return $this->call('activate', $licenseKey, $instanceId, $label);
    }

    public function validate(string $licenseKey, string $instanceId, ?string $label = null): LicenseResult
    {
        return $this->call('validate', $licenseKey, $instanceId, $label);
    }

    public function deactivate(string $licenseKey, string $instanceId): LicenseResult
    {
        return $this->call('deactivate', $licenseKey, $instanceId, null);
    }

    /**
     * Demande une licence gratuite : le serveur envoie un e-mail de confirmation, puis la clé de licence (réponse 202).
     * Un 422 (e-mail refusé) et un 429 (trop de demandes) sont des rejets ; le reste, une panne.
     */
    public function register(string $email, string $instanceId, ?string $label = null): LicenseResult
    {
        $base = $this->baseUrl();
        $apiKey = trim($this->settings->string('license.api_key'));

        if ($base === null || $apiKey === '') {
            return LicenseResult::rejected('not_configured');
        }

        $body = ['email' => $email, 'instance_id' => $instanceId];

        if ($label !== null && $label !== '') {
            $body['instance_label'] = mb_substr($label, 0, 120);
        }

        try {
            $response = $this->request($apiKey)->post($base.'/register', $body);
        } catch (Throwable) {
            return LicenseResult::unreachable();
        }

        return match ($response->status()) {
            202 => new LicenseResult(LicenseResult::ACCEPTED, null, status: $this->string($response->json('status')) ?? 'pending_verification', httpStatus: 202),
            429 => LicenseResult::rejected('rate_limited', 429),
            401, 422 => LicenseResult::rejected($this->errorCode($response->json('error')) ?? 'rejected', $response->status()),
            default => LicenseResult::unreachable($response->status()),
        };
    }

    /** L'adresse et la clé produit sont-elles réglées ? */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && trim($this->settings->string('license.api_key')) !== '';
    }

    /** Base de l'API (https seulement, la clé produit y transite ; sans slash final). */
    public function baseUrl(): ?string
    {
        $url = rtrim(trim($this->settings->string('license.api_url')), '/');

        return preg_match('#^https://[^\s]+$#i', $url) === 1 ? $url : null;
    }

    private function call(string $endpoint, string $licenseKey, string $instanceId, ?string $label): LicenseResult
    {
        $base = $this->baseUrl();
        $apiKey = trim($this->settings->string('license.api_key'));

        if ($base === null || $apiKey === '') {
            return LicenseResult::rejected('not_configured');
        }

        $body = ['license_key' => $licenseKey, 'instance_id' => $instanceId];

        if ($label !== null && $label !== '') {
            $body['instance_label'] = mb_substr($label, 0, 120);
        }

        try {
            $response = $this->request($apiKey)->post($base.'/'.$endpoint, $body);
        } catch (Throwable) {
            return LicenseResult::unreachable();
        }

        return $this->map($response);
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => 'Kintai-License/1.0'])
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('kintai.license.timeout', 8))
            // Nouvel essai seulement sur une panne de connexion ou une réponse 5xx (jamais sur 401, 422 ou 429).
            ->retry(2, 200, fn (Throwable $e): bool => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), false);
    }

    private function map(Response $response): LicenseResult
    {
        $status = $response->status();

        if ($status === 401 || $status === 422) {
            return LicenseResult::rejected($this->errorCode($response->json('error')) ?? 'rejected', $status);
        }

        if ($status !== 200) {
            return LicenseResult::unreachable($status);
        }

        $data = json_decode($response->body(), true);

        if (! is_array($data) || ! is_bool($data['valid'] ?? null)) {
            return LicenseResult::unreachable($status);
        }

        if ($data['valid'] === false) {
            return new LicenseResult(
                LicenseResult::REFUSED,
                $this->errorCode($data['error'] ?? null) ?? 'refused',
                maxActivations: $this->int($data['max_activations'] ?? null),
                httpStatus: $status,
            );
        }

        return new LicenseResult(
            LicenseResult::VALID,
            null,
            is_string($data['license_token'] ?? null) && $data['license_token'] !== '' ? $data['license_token'] : null,
            $this->string($data['status'] ?? null),
            $this->string($data['type'] ?? null),
            $this->string($data['expires_at'] ?? null),
            $this->int($data['max_activations'] ?? null),
            $status,
        );
    }

    private function errorCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9_]{1,'.self::ERROR_MAX.'}$/', $value) === 1 ? $value : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value, 0, 100) : null;
    }

    private function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
