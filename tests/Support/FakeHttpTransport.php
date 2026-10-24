<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Core\Services\Calendar\HttpTransportInterface;

/**
 * Transport HTTP factice : enregistre les requêtes et répond selon des règles « méthode + fin d'URL » (la première qui
 * correspond), ou par la réponse par défaut. Une règle peut être une liste de réponses consommées dans l'ordre.
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<array{string, string, array|callable}> */
    private array $rules = [];

    /** @param array{status: int, body: string} $default */
    public function __construct(private array $default = ['status' => 200, 'body' => '{}']) {}

    /** @param array{status: int, body: string}|list<array{status: int, body: string}>|callable $response */
    public function when(string $method, string $urlEnd, array|callable $response): self
    {
        // La règle la plus récente l’emporte : un test peut changer le comportement posé par setUp().
        array_unshift($this->rules, [strtoupper($method), $urlEnd, $response]);
        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 10): array
    {
        $this->requests[] = ['method' => strtoupper($method), 'url' => $url, 'headers' => $headers, 'body' => $body];
        foreach ($this->rules as $i => [$m, $end, $response]) {
            if ($m === strtoupper($method) && str_ends_with($url, $end)) {
                if (is_callable($response)) {
                    return $response($url, $body);
                }
                if (isset($response[0]) && is_array($response[0])) {
                    $next = array_shift($response);
                    $this->rules[$i][2] = $response === [] ? $next : $response;
                    return $next;
                }
                return $response;
            }
        }
        return $this->default;
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function callsEndingWith(string $method, string $urlEnd): array
    {
        return array_values(array_filter($this->requests, fn(array $r) => $r["method"] === strtoupper($method) && str_ends_with($r["url"], $urlEnd)));
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function calls(string $method, ?string $urlPart = null): array
    {
        return array_values(array_filter($this->requests, fn(array $r) => $r['method'] === strtoupper($method)
            && ($urlPart === null || str_contains($r['url'], $urlPart))));
    }
}
