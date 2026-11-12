<?php

namespace App\License;

use App\Settings\AppSettings;
use Carbon\CarbonImmutable;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Throwable;

/**
 * État de la licence : palier, limites, dernière vérification, et droits accordés. Rien n'est accordé sans un jeton
 * RS256 dont la signature (clé publique du serveur), l'échéance (`exp`), le produit (`kintai`) et, si réglé, l'émetteur
 * sont vérifiés, et ce à chaque lecture (aucune confiance dans la valeur stockée).
 *
 * Hors ligne, les droits sont gardés tant que le dernier jeton vérifié n'est pas expiré. Un refus explicite du serveur
 * (`valid: false` : expirée, révoquée, suspendue, non activée…) retire immédiatement les droits et le jeton. Un serveur
 * injoignable, une clé produit refusée (401), une réponse sans jeton ou au jeton invalide ne retirent jamais rien. Aucun appel réseau ici : le client est `LicenseClient`.
 */
class LicenseState
{
    /** Refus explicites du serveur et état qu'ils donnent ; les autres codes donnent `refused`. */
    private const REFUSAL_STATES = [
        'license_expired' => LicenseStatus::EXPIRED,
        'license_revoked' => LicenseStatus::REVOKED,
        'license_suspended' => LicenseStatus::SUSPENDED,
    ];

    public function __construct(private readonly AppSettings $settings) {}

    public function status(): LicenseStatus
    {
        $validatedAt = $this->validatedAt();

        if ($this->licenseKey() === '') {
            return new LicenseStatus(LicenseStatus::FREE, [], null, $validatedAt);
        }

        $refusal = $this->settings->string('license.refusal');

        if ($refusal !== '') {
            return new LicenseStatus(self::REFUSAL_STATES[$refusal] ?? LicenseStatus::REFUSED, [], $refusal, $validatedAt);
        }

        $decoded = $this->decode($this->settings->string('license.token'));
        $diagnostic = $this->settings->string('license.last_error');
        $unreachable = $diagnostic !== '';

        if ($decoded['claims'] !== null) {
            return new LicenseStatus($unreachable ? LicenseStatus::GRACE : LicenseStatus::ACTIVE, $decoded['claims'], null, $validatedAt, $diagnostic);
        }

        $state = match (true) {
            $unreachable => LicenseStatus::UNREACHABLE,
            $decoded['reason'] === 'expired' => LicenseStatus::EXPIRED,
            $decoded['reason'] === 'invalid' => LicenseStatus::REFUSED,
            default => LicenseStatus::FREE,
        };

        return new LicenseStatus($state, [], null, $validatedAt, $diagnostic);
    }

    public function state(): string
    {
        return $this->status()->state;
    }

    public function tier(): ?string
    {
        return $this->status()->tier();
    }

    /**
     * Limite d'une clé (`max_stores`, `max_employees`…) ; null = illimité. Sans licence valable, c'est le palier gratuit
     * (`kintai.license.free_limits`, à définir) qui s'applique : pour savoir si une licence est en cours, utiliser `status()`.
     */
    public function limit(string $key): ?int
    {
        $status = $this->status();
        $limits = $status->grantsRights() ? $status->limits() : (array) config('kintai.license.free_limits', []);
        $value = $limits[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function licenseKey(): string
    {
        return trim($this->settings->string('license.key'));
    }

    public function hasKey(): bool
    {
        return $this->licenseKey() !== '';
    }

    /** Identifiant stable de cette instance auprès du serveur : un UUID créé à la première utilisation. */
    public function instanceId(): string
    {
        $id = $this->settings->string('license.instance_id');

        if ($id === '') {
            $id = (string) Str::uuid();
            $this->settings->set('license.instance_id', $id);
        }

        return $id;
    }

    /** Libellé lisible de l'instance, vu par l'Owner du Manager. */
    public function instanceLabel(): string
    {
        $host = parse_url($this->settings->publicUrl() ?: (string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (string) config('app.name');
    }

    /** Page d'achat : le réglage, sinon `{base de l'API sans /api/vN}/buy/kintai` ; null si aucune adresse valable. */
    public function buyUrl(): ?string
    {
        $configured = trim($this->settings->string('license.buy_url'));

        if ($configured !== '') {
            return preg_match('#^https?://[^\s]+$#i', $configured) === 1 ? $configured : null;
        }

        $base = rtrim(trim($this->settings->string('license.api_url')), '/');

        if (preg_match('#^https?://[^\s]+$#i', $base) !== 1) {
            return null;
        }

        return preg_replace('#/api/v\d+$#', '', $base).'/buy/'.config('kintai.license.product', 'kintai');
    }

    /**
     * Enregistre le résultat d'un appel au serveur. Rend le résultat effectif : un jeton qui ne se vérifie pas transforme
     * une licence « valable » en refus `invalid_token`.
     *
     * @param  string|null  $newKey  Clé à retenir si le serveur l'accepte (activation).
     */
    public function apply(LicenseResult $result, ?string $newKey = null): LicenseResult
    {
        if ($result->isValid()) {
            // Pas de jeton, ou jeton qui ne se vérifie pas (clé du serveur cassée, par exemple) : ce n'est pas un refus du serveur,
            // on le traite comme injoignable (le jeton déjà reçu est gardé). Seul `valid: false` retire les droits.
            if ($this->decode((string) $result->token)['claims'] === null) {
                $result = new LicenseResult(LicenseResult::UNREACHABLE, 'invalid_token', httpStatus: $result->httpStatus);
            }
        }

        if ($result->isValid()) {
            $values = [
                'license.token' => (string) $result->token,
                'license.refusal' => '',
                'license.last_error' => '',
                'license.validated_at' => now()->toIso8601String(),
            ];

            if ($newKey !== null) {
                $values['license.key'] = $newKey;
            }

            $this->settings->setMany($values);

            return $result;
        }

        if ($result->isRefused()) {
            // Refus explicite : droits retirés tout de suite. Une activation refusée ne touche pas à la licence en place.
            if ($newKey === null) {
                $this->settings->setMany(['license.token' => '', 'license.refusal' => (string) $result->error, 'license.last_error' => '']);
            }

            return $result;
        }

        // Injoignable (ou requête rejetée) : on garde le jeton, on note seulement le mode dégradé et sa cause.
        if ($newKey === null && $this->hasKey()) {
            $this->settings->set('license.last_error', $result->isProductKeyRejected() ? LicenseStatus::DIAGNOSTIC_PRODUCT_KEY_REJECTED : LicenseStatus::DIAGNOSTIC_UNREACHABLE);
        }

        return $result;
    }

    /** Oublie la licence (clé, jeton, états) : retour au palier gratuit. */
    public function forget(): void
    {
        $this->settings->setMany([
            'license.key' => '', 'license.token' => '', 'license.refusal' => '',
            'license.last_error' => '', 'license.validated_at' => '',
        ]);
    }

    /**
     * Vérifie un jeton : signature RS256, échéance, produit, émetteur.
     *
     * @return array{claims: array<string, mixed>|null, reason: string} `reason` : `ok`, `missing`, `expired` ou `invalid`.
     */
    public function decode(string $token): array
    {
        $token = trim($token);
        $pem = $this->publicKey();

        if ($token === '') {
            return ['claims' => null, 'reason' => 'missing'];
        }

        if ($pem === '') {
            return ['claims' => null, 'reason' => 'invalid'];
        }

        $previous = JWT::$timestamp;
        JWT::$timestamp = now()->getTimestamp();

        try {
            $claims = json_decode((string) json_encode(JWT::decode($token, new Key($pem, 'RS256'))), true);
        } catch (ExpiredException) {
            return ['claims' => null, 'reason' => 'expired'];
        } catch (Throwable) {
            return ['claims' => null, 'reason' => 'invalid'];
        } finally {
            JWT::$timestamp = $previous;
        }

        $issuer = trim((string) config('kintai.license.issuer'));

        if (! is_array($claims) || ($claims['product'] ?? null) !== config('kintai.license.product', 'kintai')
            || ! is_int($claims['exp'] ?? null) || ($issuer !== '' && ($claims['iss'] ?? null) !== $issuer)) {
            return ['claims' => null, 'reason' => 'invalid'];
        }

        return ['claims' => $claims, 'reason' => 'ok'];
    }

    /** Clé publique du serveur (PEM) : réglage, sinon la configuration (base64 du PEM). */
    public function publicKey(): string
    {
        $pem = trim($this->settings->string('license.public_key'));

        if ($pem !== '') {
            return $pem;
        }

        $decoded = base64_decode(trim((string) config('kintai.license.public_key_b64')), true);

        return $decoded === false ? '' : trim($decoded);
    }

    private function validatedAt(): ?CarbonImmutable
    {
        $value = $this->settings->string('license.validated_at');

        try {
            return $value === '' ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
