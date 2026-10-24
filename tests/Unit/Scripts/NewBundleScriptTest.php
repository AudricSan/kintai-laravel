<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Scripts;

use kintai\Core\BundleContract\BundleManifest;
use PHPUnit\Framework\TestCase;

/** Générateur de bundle (scripts/new-bundle.php) : le squelette produit est complet, valide et prêt à tester. */
final class NewBundleScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai_newbundle_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function generate(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/new-bundle.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $output, $code);
        return [$code, implode("\n", $output)];
    }

    public function testGeneratesACompleteValidSkeleton(): void
    {
        [$code, $output] = $this->generate('my-sample', '--name=Mon exemple', '--dir=' . $this->dir);
        $this->assertSame(0, $code, $output);

        $root = $this->dir . '/kintai-bundle-my-sample';
        foreach (['bundle.json', 'composer.json', 'phpunit.xml', 'routes.php', 'src/MySampleBundle.php', 'src/Controllers/Web/MySampleController.php',
                  'Views/index.php', 'lang/fr.json', 'lang/en.json', 'lang/ja.json', 'tests/ContractTest.php', '.github/workflows/tests.yml', 'README.md', 'CHANGELOG.md'] as $file) {
            $this->assertFileExists($root . '/' . $file);
        }

        $manifest = json_decode((string) file_get_contents($root . '/bundle.json'), true);
        $this->assertSame([], BundleManifest::validationErrors($manifest, 'my-sample'));
        $this->assertSame('kintai\\Bundles\\Installed\\MySample\\MySampleBundle', $manifest['entry_class']);
        $this->assertSame('Mon exemple', $manifest['name']);

        foreach (['fr', 'en', 'ja'] as $locale) {
            $lang = json_decode((string) file_get_contents($root . "/lang/$locale.json"), true);
            $this->assertIsArray($lang, $locale);
            $this->assertArrayHasKey('bundle_my_sample', $lang);
            $this->assertArrayHasKey('my_sample_title', $lang);
        }
        $this->assertJson((string) file_get_contents($root . '/composer.json'));

        foreach (['src/MySampleBundle.php', 'src/Controllers/Web/MySampleController.php', 'routes.php', 'Views/index.php', 'tests/ContractTest.php'] as $php) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $php) . ' 2>&1', $out, $lint);
            $this->assertSame(0, $lint, $php);
        }
        $this->assertStringNotContainsString('{{', (string) file_get_contents($root . '/src/MySampleBundle.php'));
    }

    public function testRefusesToOverwriteAnExistingFolder(): void
    {
        $this->generate('taken', '--dir=' . $this->dir);
        [$code, $output] = $this->generate('taken', '--dir=' . $this->dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('existe déjà', $output);
    }

    public function testRejectsAnInvalidSlug(): void
    {
        [$code] = $this->generate('Bad_Slug', '--dir=' . $this->dir);

        $this->assertSame(2, $code);
        $this->assertDirectoryDoesNotExist($this->dir . '/kintai-bundle-Bad_Slug');
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }
        @rmdir($dir);
    }
}
