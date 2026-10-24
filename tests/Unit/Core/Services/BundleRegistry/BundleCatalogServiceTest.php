<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services\BundleRegistry;

use kintai\Core\Repositories\BundleRegistryRepositoryInterface;
use kintai\Core\Services\BundleRegistry\BundleCatalogService;
use kintai\Core\Services\BundleRegistry\BundleRegistryClient;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

final class BundleCatalogServiceTest extends TestCase
{
    public function testListAvailableBundlesAggregatesEveryActiveRegistry(): void
    {
        $registries = $this->createStub(BundleRegistryRepositoryInterface::class);
        $registries->method('all')->willReturn([
            ['id' => 1, 'name' => 'Registry officiel', 'url' => 'https://example.test/official.json', 'is_official' => true],
            ['id' => 2, 'name' => 'Registry perso',     'url' => 'https://example.test/perso.json',   'is_official' => false],
        ]);

        $bodies = [
            'https://example.test/official.json' => json_encode([
                'schema_version' => 1,
                'name'           => 'Registry officiel',
                'bundles'        => [[
                    'slug' => 'feedback', 'repository_url' => 'https://github.com/AudricSan/kintai-bundle-feedback',
                ]],
            ]),
            'https://example.test/perso.json' => json_encode([
                'schema_version' => 1,
                'name'           => 'Registry perso',
                'bundles'        => [[
                    'slug' => 'custom-bundle', 'repository_url' => 'https://github.com/someone/custom-bundle',
                ]],
            ]),
        ];

        $client = new BundleRegistryClient(fn(string $url) => $bodies[$url] ?? null);
        $service = new BundleCatalogService($registries, $client);

        $catalog = $service->listAvailableBundles();

        $this->assertCount(2, $catalog);
        $this->assertSame('feedback', $catalog[0]->bundle->slug);
        $this->assertSame('Registry officiel', $catalog[0]->registryName);
        $this->assertSame('custom-bundle', $catalog[1]->bundle->slug);
        $this->assertSame('Registry perso', $catalog[1]->registryName);
    }

    public function testListAvailableBundlesSkipsARegistryThatFailsToRespond(): void
    {
        $registries = $this->createStub(BundleRegistryRepositoryInterface::class);
        $registries->method('all')->willReturn([
            ['id' => 1, 'name' => 'Registry indisponible', 'url' => 'https://example.test/down.json', 'is_official' => false],
        ]);

        $client = new BundleRegistryClient(fn(string $url) => null);
        $service = new BundleCatalogService($registries, $client);

        $this->assertSame([], $service->listAvailableBundles());
    }

    private const PIN_SHA = '7f99e084014e8258003a8b2a8fd4d8a188cfa5c3';

    /**
     * @param array<string, ?string> $bodies URL -> corps du registry (null = injoignable)
     * @param string[]               $urls   registries configurés, dans l'ordre
     */
    private function pinService(array $urls, array $bodies): BundleCatalogService
    {
        $registries = $this->createStub(BundleRegistryRepositoryInterface::class);
        $registries->method('all')->willReturn(array_map(
            static fn(string $url) => ['id' => 1, 'name' => 'R', 'url' => $url, 'is_official' => false],
            $urls,
        ));
        return new BundleCatalogService($registries, new BundleRegistryClient(fn(string $url) => $bodies[$url] ?? null));
    }

    private function registryBody(array $commits): string
    {
        return json_encode([
            'schema_version' => 2,
            'name'           => 'R',
            'bundles'        => [[
                'slug'           => 'salary-report',
                'repository_url' => 'https://github.com/AudricSan/kintai-bundle-salary-report',
                'versions'       => ['release' => ['1.0.2'], 'beta' => ['1.0.2'], 'alpha' => ['1.0.2']],
                'commits'        => $commits,
            ]],
        ]);
    }

    public function testResolvePinReturnsTheCommitPinnedByTheRegistry(): void
    {
        $service = $this->pinService(['https://a.test/r.json'], ['https://a.test/r.json' => $this->registryBody(['1.0.2' => self::PIN_SHA])]);

        $this->assertSame(['commit' => self::PIN_SHA, 'error' => null], $service->resolvePin('https://a.test/r.json', 'salary-report', '1.0.2'));
    }

    public function testResolvePinReturnsNoCommitAndNoErrorWhenTheVersionIsNotPinned(): void
    {
        $service = $this->pinService(['https://a.test/r.json'], ['https://a.test/r.json' => $this->registryBody([])]);

        $this->assertSame(['commit' => null, 'error' => null], $service->resolvePin('https://a.test/r.json', 'salary-report', '1.0.2'));
    }

    public function testResolvePinRefusesWhenTheRegistryIsUnreachable(): void
    {
        // Faire tomber le registry ne doit pas suffire à désactiver la vérification.
        $service = $this->pinService(['https://a.test/r.json'], ['https://a.test/r.json' => null]);

        $pin = $service->resolvePin('https://a.test/r.json', 'salary-report', '1.0.2');

        $this->assertNull($pin['commit']);
        $this->assertNotNull($pin['error']);
    }

    public function testResolvePinOnlyQueriesTheRegistryTheBundleCameFrom(): void
    {
        // Un second registry (tiers) épingle un autre sha : il ne doit pas être consulté.
        $service = $this->pinService(
            ['https://a.test/r.json', 'https://b.test/r.json'],
            [
                'https://a.test/r.json' => $this->registryBody(['1.0.2' => self::PIN_SHA]),
                'https://b.test/r.json' => $this->registryBody(['1.0.2' => str_repeat('a', 40)]),
            ],
        );

        $this->assertSame(self::PIN_SHA, $service->resolvePin('https://a.test/r.json', 'salary-report', '1.0.2')['commit']);
    }

    public function testResolvePinFallsBackToAllRegistriesWhenTheUrlIsUnknown(): void
    {
        $service = $this->pinService(['https://a.test/r.json'], ['https://a.test/r.json' => $this->registryBody(['1.0.2' => self::PIN_SHA])]);

        $this->assertSame(self::PIN_SHA, $service->resolvePin('https://old.test/r.json', 'salary-report', '1.0.2')['commit']);
    }

    public function testResolvePinWithoutRegistryUrlSearchesEveryRegistry(): void
    {
        $service = $this->pinService(['https://a.test/r.json'], ['https://a.test/r.json' => $this->registryBody(['1.0.2' => self::PIN_SHA])]);

        $this->assertSame(self::PIN_SHA, $service->resolvePin(null, 'salary-report', '1.0.2')['commit']);
    }

    public function testIsOfficialUsesTheExistingOfficialBundlesConfig(): void
    {
        $service = new BundleCatalogService(
            $this->createStub(BundleRegistryRepositoryInterface::class),
            new BundleRegistryClient(fn(string $url) => null),
        );

        $this->assertTrue($service->isOfficial('timeoff'));
        $this->assertFalse($service->isOfficial('some-random-third-party-bundle'));
    }
}
