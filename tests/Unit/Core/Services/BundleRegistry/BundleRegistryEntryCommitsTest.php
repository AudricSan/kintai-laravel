<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services\BundleRegistry;

use kintai\Core\Services\BundleRegistry\BundleRegistryEntry;
use PHPUnit\Framework\TestCase;

/** Lecture de la table `commits` (version -> sha épinglé) d'une entrée de registry. */
final class BundleRegistryEntryCommitsTest extends TestCase
{
    private const SHA = '7f99e084014e8258003a8b2a8fd4d8a188cfa5c3';

    private function entry(mixed $commits): ?BundleRegistryEntry
    {
        $data = [
            'slug'           => 'salary-report',
            'repository_url' => 'https://github.com/AudricSan/kintai-bundle-salary-report',
            'versions'       => ['release' => ['1.0.2'], 'beta' => [], 'alpha' => []],
        ];
        if ($commits !== null) {
            $data['commits'] = $commits;
        }
        return BundleRegistryEntry::fromArray($data);
    }

    public function testAPinnedVersionReturnsItsCommit(): void
    {
        $this->assertSame(self::SHA, $this->entry(['1.0.2' => self::SHA])->commitFor('1.0.2'));
    }

    public function testAnUnpinnedVersionReturnsNull(): void
    {
        $this->assertNull($this->entry(['1.0.2' => self::SHA])->commitFor('1.0.3'));
    }

    public function testARegistryWithoutCommitsKeyStaysCompatible(): void
    {
        $entry = $this->entry(null);

        $this->assertSame([], $entry->commits);
        $this->assertNull($entry->commitFor('1.0.2'));
    }

    public function testMalformedPinsAreDiscardedRatherThanTrusted(): void
    {
        $entry = $this->entry([
            '1.0.2'   => 'abc123',                       // sha trop court
            '1.0.3'   => strtoupper(self::SHA),          // majuscules : format non canonique
            '1.0.4'   => 42,                             // pas une chaîne
            'latest'  => self::SHA,                      // clé qui n'est pas une version X.Y.Z
            '1.0.5'   => self::SHA,                      // seul couple valide
        ]);

        $this->assertSame(['1.0.5' => self::SHA], $entry->commits);
    }

    public function testACommitsFieldThatIsNotAnObjectIsIgnored(): void
    {
        $this->assertSame([], $this->entry('not-an-array')->commits);
    }
}
