<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Jeton anti-CSRF de l'installateur (audit SEC-01), sans session : le même jeton est posé dans un cookie SameSite=Strict
 * et renvoyé dans le formulaire (champ _install_token). Un site tiers ne peut ni lire ni envoyer ce cookie, donc ne peut
 * pas fabriquer un POST valide.
 */
final class InstallCsrf
{
    public const COOKIE = 'kintai_install_csrf';
    public const FIELD  = '_install_token';

    private bool $fromCookie;
    private string $token;

    /** @param array<string, mixed> $cookies $_COOKIE */
    public function __construct(array $cookies)
    {
        $cookie           = $cookies[self::COOKIE] ?? null;
        $this->fromCookie = is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1;
        $this->token      = $this->fromCookie ? $cookie : bin2hex(random_bytes(32));
    }

    public function token(): string
    {
        return $this->token;
    }

    /** Le cookie existait-il déjà (un POST sans cookie préalable ne peut pas être légitime) ? */
    public function hadCookie(): bool
    {
        return $this->fromCookie;
    }

    /** Le POST porte-t-il le même jeton que le cookie ? @param array<string, mixed> $post $_POST */
    public function verifies(array $post): bool
    {
        $sent = $post[self::FIELD] ?? null;

        return $this->fromCookie && is_string($sent) && hash_equals($this->token, $sent);
    }
}
