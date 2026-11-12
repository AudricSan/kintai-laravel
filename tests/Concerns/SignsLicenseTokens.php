<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;

/**
 * Paire RSA de test et jetons de licence signés comme le fait le LicenseManager (JWT RS256).
 */
trait SignsLicenseTokens
{
    private string $privatePem = '';

    private string $publicPem = '';

    private function makeKeyPair(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = @openssl_pkey_new($options) ?: openssl_pkey_new($options + ['config' => base_path('config/openssl.cnf')]);
        $this->assertNotFalse($key, 'openssl could not create a key');
        openssl_pkey_export($key, $private, null, ['config' => base_path('config/openssl.cnf')]);

        return [$private, openssl_pkey_get_details($key)['key']];
    }

    private function useTestKey(): void
    {
        [$this->privatePem, $this->publicPem] = $this->makeKeyPair();
        config(['kintai.license.public_key_b64' => base64_encode($this->publicPem)]);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function token(array $override = [], ?string $privatePem = null): string
    {
        $now = now()->getTimestamp();
        $claims = array_merge([
            'iss' => 'license-manager', 'sub' => 'lic_1', 'iat' => $now, 'exp' => $now + 14 * 86400, 'product' => 'kintai',
            'type' => 'subscription', 'plan_tier' => 'pro', 'limits' => ['max_stores' => 5, 'max_employees' => 50],
            'max_activations' => 2, 'issued_at' => now()->toIso8601String(), 'expires_at' => now()->addYear()->toIso8601String(),
        ], $override);

        return JWT::encode($claims, $privatePem ?? $this->privatePem, 'RS256');
    }
}
