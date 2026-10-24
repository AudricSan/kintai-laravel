<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services\BundleRegistry;

use kintai\Core\Services\BundleRegistry\BundleRegistryClient;
use PHPUnit\Framework\TestCase;

final class BundleRegistryClientTest extends TestCase
{
    public function testFetchListingParsesAValidRegistry(): void
    {
        $body = json_encode([
            'schema_version' => 1,
            'name'           => 'Registry officiel Kintai',
            'bundles'        => [
                [
                    'slug'           => 'feedback',
                    'name'           => 'Retours utilisateurs',
                    'description'    => 'Formulaire de feedback employé.',
                    'repository_url' => 'https://github.com/AudricSan/kintai-bundle-feedback',
                    'versions'       => ['1.0.0'],
                ],
            ],
        ]);

        $client = new BundleRegistryClient(fn(string $url) => $body);

        $listing = $client->fetchListing('https://example.test/registry.json');

        $this->assertNotNull($listing);
        $this->assertSame('Registry officiel Kintai', $listing->name);
        $this->assertCount(1, $listing->bundles);
        $this->assertSame('feedback', $listing->bundles[0]->slug);
        $this->assertSame(['1.0.0'], $listing->bundles[0]->versions);
        // Schema 1 (liste plate) : offerte à l'identique sur les trois canaux, faute de mieux.
        $this->assertSame(['1.0.0'], $listing->bundles[0]->versionsForChannel('alpha'));
    }

    public function testFetchListingParsesSchema2WithPerChannelVersions(): void
    {
        $body = json_encode([
            'schema_version' => 2,
            'name'           => 'Registry officiel Kintai',
            'bundles'        => [
                [
                    'slug'           => 'daily-report',
                    'name'           => 'Rapports journaliers',
                    'description'    => '...',
                    'repository_url' => 'https://github.com/AudricSan/kintai-bundle-daily-report',
                    'versions'       => [
                        'release' => ['1.0.0'],
                        'beta'    => ['1.1.1', '1.0.0'],
                        'alpha'   => ['1.1.1', '1.0.0'],
                    ],
                ],
            ],
        ]);

        $client = new BundleRegistryClient(fn(string $url) => $body);

        $listing = $client->fetchListing('https://example.test/registry.json');

        $this->assertNotNull($listing);
        $entry = $listing->bundles[0];
        // Compat : ->versions retombe sur le canal release.
        $this->assertSame(['1.0.0'], $entry->versions);
        $this->assertSame(['1.0.0'], $entry->versionsForChannel('release'));
        $this->assertSame(['1.1.1', '1.0.0'], $entry->versionsForChannel('beta'));
        $this->assertSame(['1.1.1', '1.0.0'], $entry->versionsForChannel('alpha'));
    }

    public function testFetchListingReturnsNullWhenFetchFails(): void
    {
        $client = new BundleRegistryClient(fn(string $url) => null);

        $this->assertNull($client->fetchListing('https://example.test/registry.json'));
    }

    public function testFetchListingReturnsNullOnInvalidJson(): void
    {
        $client = new BundleRegistryClient(fn(string $url) => 'not json at all');

        $this->assertNull($client->fetchListing('https://example.test/registry.json'));
    }

    public function testFetchListingRejectsUnsupportedSchemaVersion(): void
    {
        $body = json_encode(['schema_version' => 3, 'name' => 'Futur registry', 'bundles' => []]);

        $client = new BundleRegistryClient(fn(string $url) => $body);

        $this->assertNull($client->fetchListing('https://example.test/registry.json'));
    }

    public function testFetchListingSkipsEntriesMissingRequiredFields(): void
    {
        $body = json_encode([
            'schema_version' => 1,
            'name'           => 'Registry',
            'bundles'        => [
                ['name' => 'Sans slug ni repository_url'],
                [
                    'slug'           => 'valid-bundle',
                    'repository_url' => 'https://github.com/AudricSan/kintai-bundle-valid',
                ],
            ],
        ]);

        $client = new BundleRegistryClient(fn(string $url) => $body);

        $listing = $client->fetchListing('https://example.test/registry.json');

        $this->assertNotNull($listing);
        $this->assertCount(1, $listing->bundles);
        $this->assertSame('valid-bundle', $listing->bundles[0]->slug);
        // Le nom manquant retombe sur le slug.
        $this->assertSame('valid-bundle', $listing->bundles[0]->name);
        $this->assertSame([], $listing->bundles[0]->versions);
    }
}
