<?php

declare(strict_types=1);

namespace kintai\Core;

final class Request
{
    private readonly string $method;
    private readonly string $uri;
    private readonly array $query;
    private readonly array $post;
    private readonly array $server;
    private readonly array $cookies;
    private readonly array $files;
    private ?array $jsonBody = null;

    /** @var array<string, string> Route parameters */
    private array $routeParams = [];

    /** @var array<string, mixed> Arbitrary attributes set by middleware */
    private array $attributes = [];

    public function __construct()
    {
        $this->server = self::withAuthorizationHeader(
            $_SERVER,
            function_exists('getallheaders') ? (getallheaders() ?: []) : [],
        );
        $this->query = $_GET;
        $this->post = $_POST;
        $this->cookies = $_COOKIE;
        $this->files = $_FILES;

        // Method override: _method field or X-HTTP-Method-Override header
        $rawMethod = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($rawMethod === 'POST') {
            $override = $this->post['_method'] ?? $this->header('X-HTTP-Method-Override');
            if ($override !== null) {
                $rawMethod = strtoupper($override);
            }
        }
        $this->method = $rawMethod;

        // Parse URI (strip query string)
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        $uri = $pos !== false ? substr($uri, 0, $pos) : $uri;

        // Supprimer le préfixe du répertoire de script (ex: /MyShift/public)
        // pour que les routes soient définies sans ce préfixe.
        $scriptBase = base_url();
        if ($scriptBase !== '' && str_starts_with($uri, $scriptBase)) {
            $uri = substr($uri, strlen($scriptBase));
        }

        $this->uri = '/' . trim(rawurldecode($uri), '/');
    }

    /**
     * Apache ne place pas l'en-tête Authorization dans $_SERVER (ni sous mod_php, ni en CGI/FastCGI sans
     * réglage) : l'API à jeton répondait MISSING_TOKEN à toute requête, jeton valide compris. On le reprend,
     * dans l'ordre, de REDIRECT_HTTP_AUTHORIZATION (variable posée par public/.htaccess puis renommée par la
     * réécriture interne du .htaccess racine) ou des en-têtes bruts (getallheaders()). Une valeur déjà
     * présente (php -S, Nginx, CGIPassAuth) n'est jamais remplacée.
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $rawHeaders
     * @return array<string, mixed>
     */
    public static function withAuthorizationHeader(array $server, array $rawHeaders): array
    {
        if (($server['HTTP_AUTHORIZATION'] ?? '') !== '') {
            return $server;
        }

        $value = (string) ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($value === '') {
            foreach ($rawHeaders as $name => $headerValue) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $value = (string) $headerValue;
                    break;
                }
            }
        }

        if ($value !== '') {
            $server['HTTP_AUTHORIZATION'] = $value;
        }

        return $server;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function allQuery(): array
    {
        return $this->query;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** Le champ est-il présent dans le corps POST (même vide) ? */
    public function hasPost(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    /**
     * Case à cocher d'un formulaire de modification : 1 si cochée ('1', 'on', 'true'), 0 si envoyée
     * décochée (les vues placent un champ caché « 0 » devant chaque case), valeur actuelle si le champ est
     * absent de la requête. Avant l'audit du 13/10/2026 (M12), un champ absent valait « décoché » :
     * une requête partielle désactivait un magasin ou masquait un profil sans le vouloir.
     */
    public function postFlag(string $key, mixed $current): int
    {
        if (!$this->hasPost($key)) {
            return empty($current) ? 0 : 1;
        }
        return in_array($this->post[$key], ['1', 'on', 'true', 1, true], true) ? 1 : 0;
    }

    public function allPost(): array
    {
        return $this->post;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $this->json($key) ?? $default;
    }

    public function json(?string $key = null): mixed
    {
        if ($this->jsonBody === null) {
            $raw = file_get_contents('php://input');
            $this->jsonBody = $raw ? (json_decode($raw, true) ?? []) : [];
        }

        if ($key === null) {
            return $this->jsonBody;
        }

        return $this->jsonBody[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        // Convert header name to $_SERVER key format
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function expectsFormData(): bool
    {
        $ct = $this->header('Content-Type') ?? '';
        return str_starts_with($ct, 'multipart/form-data')
            || str_starts_with($ct, 'application/x-www-form-urlencoded');
    }

    public function expectsJson(): bool
    {
        $ct = $this->header('Content-Type') ?? '';
        return str_starts_with($ct, 'application/json');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? 'off') === 'on'
            || ($this->server['REQUEST_SCHEME'] ?? 'http') === 'https'
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($this->header('Accept') ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->uri, '/api/')
            || $this->isAjax();
    }

    // --- Route params ---

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }

    // --- Attributes (set by middleware) ---

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
