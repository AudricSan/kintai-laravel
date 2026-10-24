<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Primitive HTTP réutilisable (GET en mémoire, téléchargement vers un
 * fichier), avec le même repli curl -> wrapper de flux PHP que
 * GithubUpdateService::httpGet()/downloadZip() sur les hébergements
 * mutualisés où l'extension curl peut manquer. Introduite pour
 * BundleRegistryClient et BundleInstallerService plutôt que de dupliquer
 * cette logique dans chacun.
 */
final class HttpFetcher
{
    public function get(string $url, array $headers = [], int $timeoutSeconds = 10): ?string
    {
        if (function_exists('curl_init')) {
            return $this->getViaCurl($url, $headers, $timeoutSeconds);
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            Log::warning('http_fetcher_no_method', ['url' => $url]);
            return null;
        }

        return $this->getViaStreamWrapper($url, $headers, $timeoutSeconds);
    }

    public function post(string $url, array $headers, string $jsonBody, int $timeoutSeconds = 10): ?string
    {
        if (function_exists('curl_init')) {
            return $this->postViaCurl($url, $headers, $jsonBody, $timeoutSeconds);
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            Log::warning('http_fetcher_no_method', ['url' => $url]);
            return null;
        }

        return $this->postViaStreamWrapper($url, $headers, $jsonBody, $timeoutSeconds);
    }

    public function downloadToFile(string $url, string $destination, array $headers = [], int $timeoutSeconds = 60): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (function_exists('curl_init')) {
            return $this->downloadViaCurl($url, $destination, $headers, $timeoutSeconds);
        }

        if (!(bool) ini_get('allow_url_fopen')) {
            Log::warning('http_fetcher_no_method', ['url' => $url]);
            return false;
        }

        return $this->downloadViaStreamWrapper($url, $destination, $headers, $timeoutSeconds);
    }

    private function getViaCurl(string $url, array $headers, int $timeoutSeconds): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            Log::warning('http_fetcher_curl_failed', ['url' => $url, 'curl_error' => $error]);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            Log::warning('http_fetcher_http_error', ['url' => $url, 'status' => $status]);
            return null;
        }

        return (string) $body;
    }

    private function getViaStreamWrapper(string $url, array $headers, int $timeoutSeconds): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            Log::warning('http_fetcher_stream_failed', ['url' => $url, 'php_error' => $error['message'] ?? 'raison inconnue']);
            return null;
        }

        return $response;
    }

    private function postViaCurl(string $url, array $headers, string $jsonBody, int $timeoutSeconds): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            Log::warning('http_fetcher_curl_failed', ['url' => $url, 'curl_error' => $error]);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            Log::warning('http_fetcher_http_error', ['url' => $url, 'status' => $status]);
            return null;
        }

        return (string) $body;
    }

    private function postViaStreamWrapper(string $url, array $headers, string $jsonBody, int $timeoutSeconds): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
                'content'       => $jsonBody,
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            Log::warning('http_fetcher_stream_failed', ['url' => $url, 'php_error' => $error['message'] ?? 'raison inconnue']);
            return null;
        }

        return $response;
    }

    private function downloadViaCurl(string $url, string $destination, array $headers, int $timeoutSeconds): bool
    {
        $out = fopen($destination, 'wb');
        if ($out === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($out);

        if ($ok === false || $status >= 400) {
            @unlink($destination);
            return false;
        }

        return true;
    }

    private function downloadViaStreamWrapper(string $url, string $destination, array $headers, int $timeoutSeconds): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", $headers),
                'timeout'         => $timeoutSeconds,
                'ignore_errors'   => true,
                'follow_location' => 1,
            ],
        ]);

        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            return false;
        }

        $out = fopen($destination, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }

        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        fclose($out);

        return $ok;
    }
}
