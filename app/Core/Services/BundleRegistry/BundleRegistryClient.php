<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleRegistry;

use kintai\Core\Services\HttpFetcher;
use kintai\Core\Services\Log;

/**
 * Récupère et valide le fichier de listing (registry.json) d'un registry de
 * bundles. Volontairement un simple GET HTTP sur un fichier statique, jamais
 * un clone du dépôt du registry — voir docs/architecture.md "Modular Bundles".
 */
final class BundleRegistryClient
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param \Closure|null $fetcher fn(string $url): ?string — corps de la réponse HTTP, ou null en cas d'échec (surchargeable pour les tests)
     */
    public function __construct(
        private readonly ?\Closure $fetcher = null,
        private readonly HttpFetcher $http = new HttpFetcher(),
    ) {
    }

    public function fetchListing(string $url): ?BundleRegistryListing
    {
        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            Log::warning('bundle_registry_invalid_json', ['url' => $url]);
            return null;
        }

        $listing = BundleRegistryListing::fromArray($data);
        if ($listing === null) {
            Log::warning('bundle_registry_unsupported_schema', ['url' => $url]);
            return null;
        }

        return $listing;
    }

    private function fetch(string $url): ?string
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        return $this->http->get($url, ['User-Agent: Kintai-BundleRegistry/1.0'], self::TIMEOUT_SECONDS);
    }
}
