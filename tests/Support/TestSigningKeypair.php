<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

/**
 * Génère une paire de clés RSA 2048 bits jetable pour les tests qui exercent
 * la vérification de license_token (voir LicenseTokenVerifier, RSA-SHA256) —
 * jamais une clé codée en dur dans le code source : outre le risque de
 * sécurité (repéré par GitGuardian sur toute clé PRIVÉE committée, même de
 * test), une clé générée à la volée via l'exécutable `openssl` évite aussi
 * les soucis de portabilité d'un PEM fige entre environnements.
 */
final class TestSigningKeypair
{
    /** @return array{private: string, public: string} PEM des deux clés. */
    public static function generate(): array
    {
        $privPath = tempnam(sys_get_temp_dir(), 'rsa_priv_');
        $pubPath = tempnam(sys_get_temp_dir(), 'rsa_pub_');

        exec('openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ' . escapeshellarg($privPath) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            unlink($privPath);
            unlink($pubPath);
            throw new \RuntimeException("openssl genpkey a échoué : " . implode("\n", $out));
        }

        exec('openssl pkey -in ' . escapeshellarg($privPath) . ' -pubout -out ' . escapeshellarg($pubPath) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            unlink($privPath);
            unlink($pubPath);
            throw new \RuntimeException("openssl pkey -pubout a échoué : " . implode("\n", $out));
        }

        $private = (string) file_get_contents($privPath);
        $public = (string) file_get_contents($pubPath);
        unlink($privPath);
        unlink($pubPath);

        return ['private' => $private, 'public' => $public];
    }
}
