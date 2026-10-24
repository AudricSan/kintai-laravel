<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\BundleContract;

use kintai\Core\BundleContract\BundleManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BundleManifestTest extends TestCase
{
    private function validManifestData(array $overrides = []): array
    {
        return array_merge([
            'slug'           => 'feedback',
            'name'           => 'Retours utilisateurs',
            'version'        => '1.0.0',
            'description'    => 'Formulaire de feedback employé.',
            'namespace'      => 'kintai\\Bundles\\Installed\\Feedback',
            'entry_class'    => 'kintai\\Bundles\\Installed\\Feedback\\FeedbackBundle',
            'kintai_core'    => ['min' => '0.3.0', 'max' => '0.99.0'],
            'requires_bundles' => [],
        ], $overrides);
    }

    public function testFromArrayParsesAValidManifest(): void
    {
        $manifest = BundleManifest::fromArray($this->validManifestData());

        $this->assertNotNull($manifest);
        $this->assertSame('feedback', $manifest->slug);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('0.3.0', $manifest->kintaiCoreMin);
        $this->assertSame('0.99.0', $manifest->kintaiCoreMax);
    }

    public function testFromArrayDefaultsCoreBoundsWhenAbsent(): void
    {
        $data = $this->validManifestData();
        unset($data['kintai_core']);

        $manifest = BundleManifest::fromArray($data);

        $this->assertNotNull($manifest);
        $this->assertSame('0.0.0', $manifest->kintaiCoreMin);
        $this->assertSame('999.999.999', $manifest->kintaiCoreMax);
    }

    #[DataProvider('missingRequiredFieldProvider')]
    public function testFromArrayReturnsNullWhenARequiredFieldIsMissing(string $field): void
    {
        $data = $this->validManifestData();
        unset($data[$field]);

        $this->assertNull(BundleManifest::fromArray($data));
    }

    public static function missingRequiredFieldProvider(): array
    {
        return [
            ['slug'], ['name'], ['version'], ['namespace'], ['entry_class'],
        ];
    }

    public function testFromArrayReturnsNullForNonArrayInput(): void
    {
        $this->assertNull(BundleManifest::fromArray('not-an-array'));
        $this->assertNull(BundleManifest::fromArray(null));
    }

    public function testIsCompatibleWithCoreWithinBounds(): void
    {
        $manifest = BundleManifest::fromArray($this->validManifestData());

        $this->assertTrue($manifest->isCompatibleWithCore('0.5.0'));
        $this->assertTrue($manifest->isCompatibleWithCore('0.3.0'));
        $this->assertTrue($manifest->isCompatibleWithCore('0.99.0'));
    }

    public function testIsCompatibleWithCoreOutsideBounds(): void
    {
        $manifest = BundleManifest::fromArray($this->validManifestData());

        $this->assertFalse($manifest->isCompatibleWithCore('0.2.9'));
        $this->assertFalse($manifest->isCompatibleWithCore('1.0.0'));
    }

    public function testClassFilePathResolvesUnderSrcForItsOwnNamespace(): void
    {
        $manifest = BundleManifest::fromArray($this->validManifestData());

        $path = $manifest->classFilePath('/bundles/feedback/1.0.0', 'kintai\\Bundles\\Installed\\Feedback\\FeedbackBundle');

        $this->assertSame('/bundles/feedback/1.0.0/src/FeedbackBundle.php', $path);
    }

    public function testClassFilePathResolvesNestedClasses(): void
    {
        $manifest = BundleManifest::fromArray($this->validManifestData());

        $path = $manifest->classFilePath('/bundles/feedback/1.0.0', 'kintai\\Bundles\\Installed\\Feedback\\Controllers\\Web\\FeedbackController');

        $this->assertSame('/bundles/feedback/1.0.0/src/Controllers/Web/FeedbackController.php', $path);
    }

    public function testClassFilePathReturnsNullOutsideItsNamespace(): void
    {
        $manifest = BundleManifest::fromArray($this->validManifestData());

        $this->assertNull($manifest->classFilePath('/bundles/feedback/1.0.0', 'kintai\\Core\\Application'));
    }

    // -------------------------------------------------------------------------
    // Contrat de bundle versionné (kintai_contract)
    // -------------------------------------------------------------------------

    private function manifestWith(array $extra): \kintai\Core\BundleContract\BundleManifest
    {
        return \kintai\Core\BundleContract\BundleManifest::fromArray($extra + [
            'slug' => 'demo', 'name' => 'Demo', 'version' => '1.0.0',
            'namespace' => 'kintai\\Bundles\\Installed\\Demo', 'entry_class' => 'kintai\\Bundles\\Installed\\Demo\\DemoBundle',
        ]);
    }

    public function testTheContractConstraintIsParsedAndTakesPrecedenceOverTheCoreRange(): void
    {
        // Le contrat est satisfait : l'intervalle Core (qui exclurait 0.5.0) n'est plus consulté.
        $manifest = $this->manifestWith(['kintai_contract' => ' ^1.0 ', 'kintai_core' => ['min' => '9.0.0', 'max' => '9.9.9']]);

        $this->assertSame('^1.0', $manifest->contractConstraint);
        $this->assertNull($manifest->incompatibilityReason('0.5.0', '1.4.0'));
        $this->assertNotNull($manifest->incompatibilityReason('0.5.0', '2.0.0'));
    }

    public function testAMalformedContractConstraintMakesTheBundleIncompatible(): void
    {
        $this->assertNotNull($this->manifestWith(['kintai_contract' => 'latest'])->incompatibilityReason('1.0.0', '1.0.0'));
    }

    /** Sans contrat déclaré, le bundle est incompatible : sa plage kintai_core, même ouverte, ne compte plus. */
    public function testWithoutAContractTheBundleIsIncompatible(): void
    {
        $manifest = $this->manifestWith(['kintai_core' => ['min' => '0.1.0', 'max' => '999.999.999']]);

        $this->assertNull($manifest->contractConstraint);
        $this->assertSame('bundle_contract_missing', $manifest->incompatibilityReason('0.5.0'));
    }
}
