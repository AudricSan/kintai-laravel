<?php

declare(strict_types=1);

namespace Tests\Unit\Core\BundleContract;

use App\Bundles\BundleManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Chaque bundles/<slug>/bundle.json du monorepo est un manifeste valide, cohérent avec son dossier. */
final class MonorepoManifestsTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function bundleDirectories(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 4) . '/bundles/*/bundle.json') ?: [] as $file) {
            $cases[basename(dirname($file))] = [dirname($file)];
        }

        return $cases;
    }

    public function testTheMonorepoHoldsBundles(): void
    {
        $this->assertNotSame([], self::bundleDirectories());
    }

    #[DataProvider('bundleDirectories')]
    public function testTheManifestIsValid(string $directory): void
    {
        $manifest = json_decode((string) file_get_contents($directory . '/bundle.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame([], BundleManifest::validationErrors($manifest, basename($directory)));
    }

    #[DataProvider('bundleDirectories')]
    public function testTheEntryClassFileExists(string $directory): void
    {
        $manifest = json_decode((string) file_get_contents($directory . '/bundle.json'), true);
        $class    = substr((string) $manifest['entry_class'], strlen((string) $manifest['namespace']) + 1);

        $this->assertFileExists($directory . '/src/' . str_replace('\\', '/', $class) . '.php');
    }

    #[DataProvider('bundleDirectories')]
    public function testTheHomepageIsTheBundleFolder(string $directory): void
    {
        $manifest = json_decode((string) file_get_contents($directory . '/bundle.json'), true);

        $this->assertStringEndsWith('/bundles/' . basename($directory), (string) $manifest['homepage']);
    }
}
