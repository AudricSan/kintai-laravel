<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\BundleContract;

use kintai\Core\BundleContract\BundleManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Schéma de bundle.json : une phrase précise par défaut, pour l'installation et la CI des bundles. */
final class BundleManifestSchemaTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'slug'             => 'daily-report',
            'name'             => 'Rapport journalier',
            'version'          => '1.2.0',
            'description'      => 'x',
            'kintai_contract'  => '^1.25',
            'kintai_core'      => ['min' => '0.3.6', 'max' => '999.999.999'],
            'requires_bundles' => [],
            'namespace'        => 'kintai\\Bundles\\Installed\\DailyReport',
            'entry_class'      => 'kintai\\Bundles\\Installed\\DailyReport\\DailyReportBundle',
        ], $overrides);
    }

    public function testAValidManifestHasNoError(): void
    {
        $this->assertSame([], BundleManifest::validationErrors($this->valid(), 'daily-report'));
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function invalidCases(): array
    {
        return [
            'not an object'          => [[], 'obligatoire'],
            'slug with capitals'     => [['slug' => 'Daily_Report'], 'minuscules'],
            'bad version'            => [['version' => '1.2'], 'X.Y.Z'],
            'unreadable constraint'  => [['kintai_contract' => 'latest'], 'illisible'],
            'bad namespace'          => [['namespace' => 'kintai/Bundles'], 'namespace PHP valide'],
            'entry outside namespace' => [['entry_class' => 'Other\\Thing'], 'doit se trouver'],
            'bad core bound'         => [['kintai_core' => ['min' => 'abc']], 'kintai_core.min'],
            'requires not object'    => [['requires_bundles' => 'timeoff'], 'requires_bundles'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function testEachDefectIsReported(array $overrides, string $expected): void
    {
        $data = $overrides === [] ? [] : $this->valid($overrides);
        $errors = BundleManifest::validationErrors($data, null);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($expected, implode(' ', $errors));
    }

    public function testASlugDifferentFromTheExpectedOneIsReported(): void
    {
        $errors = BundleManifest::validationErrors($this->valid(), 'timeoff');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('timeoff', $errors[0]);
    }

    public function testTheContractCanBeLeftOutForTheInstallerWhichHasItsOwnMessage(): void
    {
        $data = $this->valid();
        unset($data['kintai_contract']);

        $this->assertNotEmpty(BundleManifest::validationErrors($data));
        $this->assertSame([], BundleManifest::validationErrors($data, null, false));
    }

    public function testNonJsonDataIsRejected(): void
    {
        $this->assertSame(['bundle.json n\'est pas un objet JSON valide.'], BundleManifest::validationErrors(null));
    }
}
