<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Contract;

use kintai\Tests\Support\ContractSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Instantané du contrat (contract.snapshot.json) : il doit correspondre au contrat réel, et la règle de version
 * (retrait/changement d'une entrée stable = version majeure) est vérifiée sur des cas écrits.
 */
final class ContractSnapshotTest extends TestCase
{
    public function testTheVersionedSnapshotMatchesTheRealContract(): void
    {
        $file = dirname(__DIR__, 3) . '/contract.snapshot.json';
        $this->assertFileExists($file);

        $snapshot = json_decode((string) file_get_contents($file), true);
        $this->assertSame(
            $snapshot,
            ContractSnapshot::export(),
            'Le contrat a changé : lancez « php scripts/export-contract.php --write » (la règle de version est vérifiée) et commitez contract.snapshot.json.'
        );
    }

    /** @return array<string, mixed> */
    private function base(): array
    {
        return [
            'version'      => '1.4.0',
            'surface'      => ['kintai\\Contract\\A' => ['status' => 'stable', 'target' => 'X'], 'kintai\\Contract\\P' => ['status' => 'provisoire', 'target' => 'Y']],
            'functions'    => ['f' => ['status' => 'stable', 'params' => ['a'], 'returns' => 'string']],
            'translations' => ['save' => 'stable'],
            'bundle'       => ['navigation' => '(): array'],
        ];
    }

    public function testNothingChangedIsAlwaysAllowed(): void
    {
        $this->assertSame([], ContractSnapshot::versionViolations($this->base(), $this->base()));
    }

    public function testAnAdditionNeedsAHigherVersionButNotAMajorOne(): void
    {
        $new = $this->base();
        $new['functions']['g'] = ['status' => 'stable', 'params' => [], 'returns' => 'string'];

        $this->assertNotSame([], ContractSnapshot::versionViolations($this->base(), $new), 'même version : refusé');

        $new['version'] = '1.5.0';
        $this->assertSame([], ContractSnapshot::versionViolations($this->base(), $new));
    }

    public function testRemovingOrChangingAStableEntryNeedsAMajorVersion(): void
    {
        foreach ([
            fn(array $c) => array_replace($c, ['surface' => ['kintai\\Contract\\P' => $c['surface']['kintai\\Contract\\P']]]),
            fn(array $c) => array_replace($c, ['functions' => ['f' => ['status' => 'stable', 'params' => ['a', 'b'], 'returns' => 'string']]]),
            fn(array $c) => array_replace($c, ['translations' => []]),
            fn(array $c) => array_replace($c, ['bundle' => ['navigation' => '(): iterable']]),
        ] as $mutate) {
            $new = $mutate($this->base());
            $new['version'] = '1.5.0';
            $this->assertNotSame([], ContractSnapshot::versionViolations($this->base(), $new));

            $new['version'] = '2.0.0';
            $this->assertSame([], ContractSnapshot::versionViolations($this->base(), $new));
        }
    }

    public function testRemovingAProvisionalEntryIsNotBreaking(): void
    {
        $new = $this->base();
        unset($new['surface']['kintai\\Contract\\P']);
        $new['version'] = '1.5.0';

        $this->assertSame([], ContractSnapshot::versionViolations($this->base(), $new));
    }
}
