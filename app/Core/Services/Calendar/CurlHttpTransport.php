<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

/** Transport curl, avec repli sur le wrapper de flux PHP (hébergements mutualisés sans curl), qui expose le code HTTP. */
final class CurlHttpTransport implements HttpTransportInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 10): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $lines,
                CURLOPT_TIMEOUT        => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['status' => $response === false ? 0 : $status, 'body' => $response === false ? '' : (string) $response];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $lines),
                'content'       => $body ?? '',
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['status' => 0, 'body' => ''];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => $response];
    }
}
