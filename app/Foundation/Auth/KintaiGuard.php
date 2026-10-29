<?php

declare(strict_types=1);

namespace App\Foundation\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use App\Auth\AuthService;
use Illuminate\Container\Container;
use App\Repositories\UserRepositoryInterface;

/**
 * Garde d'authentification Laravel adossée à celle de Kintai : auth()->user(), auth()->check(), Auth::id(), @auth…
 * rendent l'utilisateur connecté au sens d'AuthService (session, compte actif, empreinte du mot de passe).
 *
 * La connexion et la déconnexion restent celles d'AuthService (AuthController) : cette garde ne fait que lire.
 */
final class KintaiGuard implements Guard
{
    /** Utilisateur posé par setUser() pour la requête en cours (tests, connexion programmée). */
    private ?Authenticatable $user = null;

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $user = $this->auth()->user();

        return $user !== null ? new KintaiUser($user) : null;
    }

    public function id(): ?int
    {
        return $this->user()?->getAuthIdentifier();
    }

    /** @param array<string, mixed> $credentials email et password */
    public function validate(array $credentials = []): bool
    {
        $email    = (string) ($credentials['email'] ?? '');
        $password = (string) ($credentials['password'] ?? '');
        if ($email === '' || $password === '') {
            return false;
        }

        $user = Container::getInstance()->make(UserRepositoryInterface::class)->findByEmail($email);

        return $user !== null && !empty($user['is_active']) && empty($user['deleted_at'])
            && password_verify($password, (string) ($user['password_hash'] ?? ''));
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    private function auth(): AuthService
    {
        return Container::getInstance()->make(AuthService::class);
    }
}
