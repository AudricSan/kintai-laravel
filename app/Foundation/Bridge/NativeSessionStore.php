<?php

declare(strict_types=1);

namespace kintai\Foundation\Bridge;

use Illuminate\Contracts\Session\Session;

/**
 * Session Laravel (« session ») adossée à la session PHP native ($_SESSION) qu'ouvre SessionMiddleware.
 *
 * Étape de transition de la migration : Kintai lit et écrit encore $_SESSION directement. csrf_token(), session() et
 * les autres helpers de Laravel voient ainsi les mêmes données, à commencer par le jeton CSRF (_csrf_token, contrôlé
 * par CsrfMiddleware).
 */
final class NativeSessionStore implements Session
{
    private const TOKEN_KEY = '_csrf_token';

    public function getName(): string
    {
        return session_name() ?: 'kintai_session';
    }

    public function setName($name): void
    {
        session_name((string) $name);
    }

    public function getId(): string
    {
        return (string) session_id();
    }

    public function setId($id): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id((string) $id);
        }
    }

    public function start(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE || @session_start();
    }

    public function save(): void
    {
        // La session PHP est écrite par PHP en fin de requête.
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }

    public function exists($key): bool
    {
        foreach ((array) $key as $k) {
            if (!array_key_exists($k, $_SESSION ?? [])) {
                return false;
            }
        }
        return true;
    }

    public function has($key): bool
    {
        foreach ((array) $key as $k) {
            if (($_SESSION[$k] ?? null) === null) {
                return false;
            }
        }
        return true;
    }

    public function get($key, $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function pull($key, $default = null): mixed
    {
        $value = $this->get($key, $default);
        unset($_SESSION[$key]);
        return $value;
    }

    public function put($key, $value = null): void
    {
        foreach (is_array($key) ? $key : [$key => $value] as $k => $v) {
            $_SESSION[$k] = $v;
        }
    }

    public function flash(string $key, $value = true): void
    {
        $_SESSION[$key] = $value;
    }

    public function token(): string
    {
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    public function regenerateToken(): void
    {
        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
    }

    public function remove($key): mixed
    {
        return $this->pull($key);
    }

    public function forget($keys): void
    {
        foreach ((array) $keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    public function flush(): void
    {
        $_SESSION = [];
    }

    public function invalidate(): bool
    {
        $this->flush();
        return $this->migrate(true);
    }

    public function regenerate($destroy = false): bool
    {
        return $this->migrate($destroy);
    }

    public function migrate($destroy = false): bool
    {
        return session_status() === PHP_SESSION_ACTIVE ? session_regenerate_id((bool) $destroy) : true;
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function previousUrl(): ?string
    {
        return $_SESSION['_previous']['url'] ?? null;
    }

    public function setPreviousUrl($url): void
    {
        $_SESSION['_previous']['url'] = $url;
    }

    public function getHandler(): \SessionHandlerInterface
    {
        return new \SessionHandler();
    }

    public function handlerNeedsRequest(): bool
    {
        return false;
    }

    public function setRequestOnHandler($request): void
    {
    }
}
