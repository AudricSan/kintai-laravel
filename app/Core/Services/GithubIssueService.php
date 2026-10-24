<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Crée des issues sur le dépôt GitHub du projet depuis le formulaire "Signaler
 * un problème" du footer, sans faire quitter l'application à l'utilisateur
 * (contrairement à un simple lien github.com/issues/new pré-rempli).
 *
 * Repose sur GITHUB_ISSUES_TOKEN (scope "public_repo" minimum) : sans ce
 * jeton configuré, isConfigured() retourne false et l'appelant doit se
 * rabattre sur un lien GitHub pré-rempli (voir SupportController).
 */
final class GithubIssueService
{
    private string $repo;
    private string $token;

    public function __construct(
        private readonly ?\Closure $httpPoster = null,
    ) {
        $this->repo = env('GITHUB_UPDATE_REPO', 'AudricSan/Kintai');
        $this->token = env('GITHUB_ISSUES_TOKEN', '');
    }

    public function isConfigured(): bool
    {
        return $this->repo !== '' && $this->token !== '';
    }

    /** URL vers laquelle rabattre le signalement quand aucun jeton n'est configuré (pré-rempli, ouvert dans un nouvel onglet). */
    public function fallbackUrl(string $title, string $description): string
    {
        return 'https://github.com/' . $this->repo . '/issues/new?' . http_build_query([
            'title' => $title,
            'body'  => $description,
        ]);
    }

    /**
     * @return array{ok: bool, issue_url?: string, error?: string}
     */
    public function createIssue(string $title, string $body): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $payload = json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['ok' => false, 'error' => 'encode_failed'];
        }

        $url = "https://api.github.com/repos/{$this->repo}/issues";
        $headers = [
            'User-Agent: Kintai-IssueReport/1.0',
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
        ];

        $response = $this->httpPoster !== null
            ? ($this->httpPoster)($url, $headers, $payload)
            : $this->httpPost($url, $headers, $payload);

        if ($response === null) {
            return ['ok' => false, 'error' => 'request_failed'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['html_url'])) {
            Log::warning('github_issue_create_failed', ['github_message' => is_array($data) ? ($data['message'] ?? null) : null]);
            return ['ok' => false, 'error' => 'invalid_response'];
        }

        return ['ok' => true, 'issue_url' => (string) $data['html_url']];
    }

    private function httpPost(string $url, array $headers, string $payload): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            Log::warning('github_issue_curl_failed', ['curl_error' => $error]);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            Log::warning('github_issue_http_error', ['status' => $status, 'body' => $body]);
        }

        return (string) $body;
    }
}
