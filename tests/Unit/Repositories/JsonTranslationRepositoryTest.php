<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\JsonTranslationRepository;

final class JsonTranslationRepositoryTest extends TestCase
{
    private string $langPath;
    private JsonTranslationRepository $repo;

    protected function setUp(): void
    {
        $this->langPath = sys_get_temp_dir() . '/kintai_translrepo_' . uniqid();
        mkdir($this->langPath, 0755, true);
        $this->repo = new JsonTranslationRepository($this->langPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->langPath . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->langPath);
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
        file_put_contents($this->langPath . '/languages.json', json_encode([['code' => 'fr']]));
        $this->repo->save('fr', 'hello', 'Bonjour');
        $this->repo->save('en', 'hello', 'Hello');
        $this->repo->save('fr', 'bye', 'Au revoir');

        $keys = $this->repo->findAllKeys();
        sort($keys);

        $this->assertSame(['bye', 'hello'], $keys);
    }

    public function testDeleteRemovesKeyFromOneLocaleOnly(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour');
        $this->repo->save('en', 'hello', 'Hello');

        $result = $this->repo->delete('fr', 'hello');

        $this->assertSame(1, $result);
        $this->assertNull($this->repo->findValue('fr', 'hello'));
        $this->assertSame('Hello', $this->repo->findValue('en', 'hello'));
    }

    public function testDeleteReturnsZeroWhenKeyMissing(): void
    {
        $this->assertSame(0, $this->repo->delete('fr', 'unknown'));
    }

    public function testCountByLocale(): void
    {
        $this->repo->save('fr', 'hello', 'Bonjour');
        $this->repo->save('fr', 'bye', 'Au revoir');

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
    // Fusion avec les lang/*.json des bundles (app/Bundles/<Name>/lang/*.json)
    // -------------------------------------------------------------------------

    private string $bundlesDir;
    private JsonTranslationRepository $repoWithBundles;

    private function makeBundleLangFile(string $bundle, string $locale, array $data): void
    {
        $dir = $this->bundlesDir . '/' . $bundle . '/lang';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $locale . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
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

    public function testBundleTranslationsAreMergedOnTopOfCore(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $this->bundlesDir = sys_get_temp_dir() . '/kintai_translrepo_bundles_' . uniqid();
        $this->makeBundleLangFile('Feedback', 'fr', ['feedback_title' => 'Retours']);
        $this->repoWithBundles = new JsonTranslationRepository($this->langPath, $this->bundlesDir);

        $merged = $this->repoWithBundles->findByLocale('fr');

        $this->assertSame('Enregistrer', $merged['save']);
        $this->assertSame('Retours', $merged['feedback_title']);

        $this->removeDirRecursive($this->bundlesDir);
    }

    public function testKeyMissingFromBundleFallsBackToCoreValue(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $this->bundlesDir = sys_get_temp_dir() . '/kintai_translrepo_bundles_' . uniqid();
        $this->makeBundleLangFile('Feedback', 'fr', ['feedback_title' => 'Retours']);
        $this->repoWithBundles = new JsonTranslationRepository($this->langPath, $this->bundlesDir);

        // 'save' n'est défini que dans le Core : le bundle en hérite sans le redéfinir.
        $this->assertSame('Enregistrer', $this->repoWithBundles->findValue('fr', 'save'));

        $this->removeDirRecursive($this->bundlesDir);
    }

    public function testBundleCanOverrideACoreKey(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $this->bundlesDir = sys_get_temp_dir() . '/kintai_translrepo_bundles_' . uniqid();
        $this->makeBundleLangFile('Feedback', 'fr', ['save' => 'Envoyer']);
        $this->repoWithBundles = new JsonTranslationRepository($this->langPath, $this->bundlesDir);

        $this->assertSame('Envoyer', $this->repoWithBundles->findValue('fr', 'save'));

        $this->removeDirRecursive($this->bundlesDir);
    }

    public function testSavingAnExistingBundleKeyWritesBackToTheBundleFile(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $this->bundlesDir = sys_get_temp_dir() . '/kintai_translrepo_bundles_' . uniqid();
        $this->makeBundleLangFile('Feedback', 'fr', ['feedback_title' => 'Retours']);
        $this->repoWithBundles = new JsonTranslationRepository($this->langPath, $this->bundlesDir);

        $this->repoWithBundles->save('fr', 'feedback_title', 'Avis');

        $bundleFile = $this->bundlesDir . '/Feedback/lang/fr.json';
        $coreFile   = $this->langPath . '/fr.json';
        $this->assertSame('Avis', json_decode(file_get_contents($bundleFile), true)['feedback_title']);
        $this->assertArrayNotHasKey('feedback_title', json_decode(file_get_contents($coreFile), true));

        $this->removeDirRecursive($this->bundlesDir);
    }

    public function testNewKeySavedThroughTheAggregatedRepositoryDefaultsToCore(): void
    {
        $this->bundlesDir = sys_get_temp_dir() . '/kintai_translrepo_bundles_' . uniqid();
        mkdir($this->bundlesDir, 0755, true);
        $this->repoWithBundles = new JsonTranslationRepository($this->langPath, $this->bundlesDir);

        $this->repoWithBundles->save('fr', 'brand_new_key', 'Valeur');

        $this->assertSame(
            'Valeur',
            json_decode(file_get_contents($this->langPath . '/fr.json'), true)['brand_new_key']
        );

        $this->removeDirRecursive($this->bundlesDir);
    }

    public function testFindAllKeysIncludesBundleKeys(): void
    {
        file_put_contents($this->langPath . '/fr.json', json_encode(['save' => 'Enregistrer']));
        $this->bundlesDir = sys_get_temp_dir() . '/kintai_translrepo_bundles_' . uniqid();
        $this->makeBundleLangFile('Feedback', 'fr', ['feedback_title' => 'Retours']);
        $this->repoWithBundles = new JsonTranslationRepository($this->langPath, $this->bundlesDir);

        $keys = $this->repoWithBundles->findAllKeys();
        sort($keys);

        $this->assertSame(['feedback_title', 'save'], $keys);

        $this->removeDirRecursive($this->bundlesDir);
    }
}
