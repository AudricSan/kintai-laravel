<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use App\Repositories\JsonTranslationRepository;

final class JsonTranslationRepositoryTest extends TestCase
{
    private string $langPath;
    private FileLoader $loader;
    private JsonTranslationRepository $repo;

    protected function setUp(): void
    {
        $this->langPath = sys_get_temp_dir() . '/kintai_translrepo_' . uniqid();
        mkdir($this->langPath, 0755, true);
        $this->loader = new FileLoader(new Filesystem(), $this->langPath);
        $this->repo = new JsonTranslationRepository($this->langPath, new Translator($this->loader, 'en'));
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->langPath);
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSaveCreatesFileAndKey(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour');

        $this->assertFileExists($this->langPath . '/fr.json');
        $this->assertSame('Bonjour', $this->repo->findValue('fr', 'hello'));
    }

    public function testSaveUpsertsExistingKey(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour');
        $this->repo->save('fr', 'hello', 'Salut');

        $this->assertSame('Salut', $this->repo->findValue('fr', 'hello'));
        $this->assertCount(1, $this->repo->findByLocale('fr'));
    }

    public function testFindByLocaleReturnsAllKeysForThatLocaleOnly(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour');
        $this->repo->save('fr', 'bye', 'Au revoir');
        $this->repo->save('en', 'hello', 'Hello');

        $this->assertSame(['bye' => 'Au revoir', 'hello' => 'Bonjour'], $this->repo->findByLocale('fr'));
    }

    public function testFindValueReturnsNullWhenMissingLocaleOrKey(): void
    {
        $this->assertNull($this->repo->findValue('fr', 'unknown'));
        $this->assertNull($this->repo->findValue('unknown_locale', 'hello'));
    }

    public function testFindAllKeysUnionsAcrossLocalesButIgnoresManifest(): void
    {
        $this->repo->save('fr', 'a', 'A');
        $this->repo->save('en', 'b', 'B');
        file_put_contents($this->langPath . '/languages.json', json_encode([['code' => 'fr']]));

        $keys = $this->repo->findAllKeys();
        sort($keys);

        $this->assertSame(['a', 'b'], $keys);
    }

    public function testDeleteRemovesKeyFromOneLocaleOnly(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour');
        $this->repo->save('en', 'hello', 'Hello');

        $this->assertSame(1, $this->repo->delete('fr', 'hello'));
        $this->assertNull($this->repo->findValue('fr', 'hello'));
        $this->assertSame('Hello', $this->repo->findValue('en', 'hello'));
    }

    public function testDeleteReturnsZeroWhenKeyMissing(): void
    {
        $this->assertSame(0, $this->repo->delete('fr', 'nope'));
    }

    public function testCountByLocale(): void
    {
        $this->repo->save('fr', 'a', 'A');
        $this->repo->save('fr', 'b', 'B');

        $this->assertSame(2, $this->repo->countByLocale('fr'));
        $this->assertSame(0, $this->repo->countByLocale('ja'));
    }

    public function testFileIsValidJsonAfterSave(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour éàü€');

        $raw = file_get_contents($this->langPath . '/fr.json');
        $this->assertIsArray(json_decode($raw, true));
        $this->assertStringContainsString('éàü€', $raw);
    }

    // -------------------------------------------------------------------------
    // lang/ des bundles, ajouté au chargeur de Laravel par loadJsonTranslationsFrom()
    // -------------------------------------------------------------------------

    private function addBundleLang(string $locale, array $data): string
    {
        $dir = sys_get_temp_dir() . '/kintai_translrepo_bundle_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $locale . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->loader->addJsonPath($dir);

        return $dir;
    }

    public function testBundleTranslationsAreMergedWithTheApplicationOnes(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $dir = $this->addBundleLang('fr', ['feedback_title' => 'Retours']);

        $merged = $this->repo->findByLocale('fr');

        $this->assertSame('Enregistrer', $merged['save']);
        $this->assertSame('Retours', $merged['feedback_title']);
        $this->removeDirRecursive($dir);
    }

    public function testTheApplicationOverridesABundleKey(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $dir = $this->addBundleLang('fr', ['save' => 'Envoyer']);

        $this->assertSame('Enregistrer', $this->repo->findValue('fr', 'save'));
        $this->removeDirRecursive($dir);
    }

    public function testSavingABundleKeyWritesItInTheApplicationFile(): void
    {
        $dir = $this->addBundleLang('fr', ['feedback_title' => 'Retours']);

        $this->repo->save('fr', 'feedback_title', 'Avis');

        $this->assertSame('Avis', json_decode(file_get_contents($this->langPath . '/fr.json'), true)['feedback_title']);
        $this->assertSame('Avis', $this->repo->findValue('fr', 'feedback_title'));
        $this->assertSame('Retours', json_decode(file_get_contents($dir . '/fr.json'), true)['feedback_title']);
        $this->removeDirRecursive($dir);
    }

    public function testFindAllKeysIncludesBundleKeys(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $dir = $this->addBundleLang('fr', ['feedback_title' => 'Retours']);

        $keys = $this->repo->findAllKeys();
        sort($keys);

        $this->assertSame(['feedback_title', 'save'], $keys);
        $this->removeDirRecursive($dir);
    }
}
