<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UrlGuard;
use Illuminate\Support\Facades\Log;

/**
 * Primitive HTTP réutilisable (GET en mémoire, téléchargement vers un fichier), avec le repli curl -> wrapper de flux
 * PHP sur les hébergements mutualisés où l'extension curl peut manquer. Utilisée par BundleRegistryClient,
 * BundleInstallerService et LicenseClientService.
 *
 * Audit SEC-08 : chaque adresse (y compris chaque redirection, suivie à la main, trois au plus) passe par UrlGuard
 * (HTTPS, domaine public) et, avec curl, l'adresse IP validée est épinglée (CURLOPT_RESOLVE) pour que la connexion
 * aille bien où la validation a regardé. La taille d'un téléchargement est bornée.
 */
final class HttpFetcher
{
    private const MAX_REDIRECTS = 3;
    public const MAX_DOWNLOAD_BYTES = 209715200; // 200 Mo
    private const MAX_BODY_BYTES = 33554432;     // 32 Mo pour une réponse gardée en mémoire

    public function get(string $url, array $headers = [], int $timeoutSeconds = 10): ?string
    {
        if (function_exists('curl_init')) {
            $r = $this->curl('GET', $url, $headers, null, $timeoutSeconds, null);
            return $r === null ? null : $r['body'];
        }

        return $this->viaStreamWrapper('GET', $url, $headers, null, $timeoutSeconds);
    }

    public function post(string $url, array $headers, string $jsonBody, int $timeoutSeconds = 10): ?string
    {
        $headers = array_merge(['Content-Type: application/json'], $headers);
        if (function_exists('curl_init')) {
            $r = $this->curl('POST', $url, $headers, $jsonBody, $timeoutSeconds, null);
            return $r === null ? null : $r['body'];
        }

        return $this->viaStreamWrapper('POST', $url, $headers, $jsonBody, $timeoutSeconds);
    }

    public function downloadToFile(string $url, string $destination, array $headers = [], int $timeoutSeconds = 60): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (function_exists('curl_init')) {
            $r = $this->curl('GET', $url, $headers, null, $timeoutSeconds, $destination);
            if ($r === null) {
                @unlink($destination);
            }
            return $r !== null;
        }

        $body = $this->viaStreamWrapper('GET', $url, $headers, null, $timeoutSeconds, self::MAX_DOWNLOAD_BYTES);
        if ($body === null || file_put_contents($destination, $body) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}|null null en cas de refus, d'échec ou de statut >= 400
     */
    private function curl(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, ?string $sinkFile): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = UrlGuard::check($url);
            if (is_string($target)) {
                Log::warning('http_fetcher_refused', ['url' => $url, 'reason' => $target]);
                return null;
            }

            $sink = $sinkFile !== null ? fopen($sinkFile, 'wb') : null;
            if ($sinkFile !== null && $sink === false) {
                return null;
            }
            $responseHeaders = [];
            $received = 0;
            $limit    = $sinkFile !== null ? self::MAX_DOWNLOAD_BYTES : self::MAX_BODY_BYTES;
            $buffer   = '';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST   => $method,
                CURLOPT_HTTPHEADER      => $headers,
                CURLOPT_TIMEOUT         => $timeoutSeconds,
                CURLOPT_FOLLOWLOCATION  => false,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_RESOLVE         => [sprintf('%s:%d:%s', $target['host'], $target['port'], $target['ip'])],
                CURLOPT_HEADERFUNCTION  => static function ($c, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION   => static function ($c, string $chunk) use (&$received, $limit, $sink, &$buffer): int {
                    $received += strlen($chunk);
                    if ($received > $limit) {
                        return 0; // dépassement : curl interrompt le transfert
                    }
                    if ($sink !== null) {
                        fwrite($sink, $chunk);
                    } else {
                        $buffer .= $chunk;
                    }
                    return strlen($chunk);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $ok     = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_error($ch);
            curl_close($ch);
            if ($sink !== null) {
                fclose($sink);
            }

            if ($status >= 300 && $status < 400 && isset($responseHeaders['location'])) {
                $url    = self::resolveLocation($url, $responseHeaders['location']);
                $method = 'GET';
                $body   = null;
                continue;
            }
            if ($ok === false) {
                Log::warning('http_fetcher_curl_failed', ['url' => $url, 'curl_error' => $error]);
                return null;
            }
            if ($status >= 400) {
                Log::warning('http_fetcher_http_error', ['url' => $url, 'status' => $status]);
                return null;
            }

            return ['status' => $status, 'body' => $buffer];
        }

        Log::warning('http_fetcher_too_many_redirects', ['url' => $url]);
        return null;
    }

    /**
     * Repli sans curl : la validation ne porte que sur l'adresse de départ (le wrapper de flux ne permet pas de
     * contrôler chaque redirection), qui est limitée à trois.
     *
     * @param list<string> $headers
     */
    private function viaStreamWrapper(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, int $maxBytes = self::MAX_BODY_BYTES): ?string
    {
        if (!(bool) ini_get('allow_url_fopen')) {
            Log::warning('http_fetcher_no_method', ['url' => $url]);
            return null;
        }
        $target = UrlGuard::check($url);
        if (is_string($target)) {
            Log::warning('http_fetcher_refused', ['url' => $url, 'reason' => $target]);
            return null;
        }

        $http = [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => $timeoutSeconds,
            'ignore_errors' => true,
            'max_redirects' => self::MAX_REDIRECTS,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $context = stream_context_create([
            'http' => $http,
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if ($response === false) {
            $error = error_get_last();
            Log::warning('http_fetcher_stream_failed', ['url' => $url, 'php_error' => $error['message'] ?? 'raison inconnue']);
            return null;
        }
        if (strlen($response) > $maxBytes) {
            return null;
        }
        $status = 200;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status >= 400 ? null : $response;
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $root  = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }

        return $root . rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $location;
    }
}
