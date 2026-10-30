<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\InstalledBundle;

/**
 * Attentes sur la table installed_bundles pour les tests de l'installateur et du marché de bundles : lignes préparées
 * avant l'action, vérifiées à la fin du test (rien n'a changé, un bundle a été enregistré ou retiré).
 */
trait InstalledBundleExpectations
{
    /** @var list<array{slug: string, active_version: string, source_registry_url: ?string}> */
    private array $seededInstalled = [];
    private bool $expectNoInstalledChange = false;
    /** @var list<array{string, string, string|null}> */
    private array $expectedUpserts = [];
    /** @var list<string> */
    private array $expectedDeletes = [];

    /** Prépare la base : ce bundle est déjà installé. */
    private function seedInstalled(string $slug, string $version, ?string $sourceRegistryUrl = null): void
    {
        InstalledBundle::create(['slug' => $slug, 'active_version' => $version, 'source_registry_url' => $sourceRegistryUrl]);
        $this->seededInstalled[] = ['slug' => $slug, 'active_version' => $version, 'source_registry_url' => $sourceRegistryUrl];
    }

    private function expectNoInstalledChange(): void
    {
        $this->expectNoInstalledChange = true;
    }

    /** @param string|null $sourceRegistryUrl '*' : adresse du registry non vérifiée */
    private function expectInstalledUpsert(string $slug, ?string $version = null, ?string $sourceRegistryUrl = '*'): void
    {
        $this->expectedUpserts[] = [$slug, $version ?? '', $sourceRegistryUrl];
    }

    private function expectInstalledDelete(string $slug): void
    {
        $this->expectedDeletes[] = $slug;
    }

    protected function assertPostConditions(): void
    {
        if ($this->expectNoInstalledChange) {
            $actual = InstalledBundle::orderBy('slug')->get(['slug', 'active_version', 'source_registry_url'])->toArray();
            $seeded = $this->seededInstalled;
            usort($seeded, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
            $this->assertSame($seeded, $actual, 'installed_bundles ne devait pas changer');
        }
        foreach ($this->expectedUpserts as [$slug, $version, $url]) {
            $row = InstalledBundle::find($slug);
            $this->assertNotNull($row, "bundle {$slug} enregistré");
            if ($version !== '') {
                $this->assertSame($version, $row->active_version);
            }
            if ($url !== '*') {
                $this->assertSame($url, $row->source_registry_url);
            }
        }
        foreach ($this->expectedDeletes as $slug) {
            $this->assertNull(InstalledBundle::find($slug), "bundle {$slug} retiré");
        }
    }
}
