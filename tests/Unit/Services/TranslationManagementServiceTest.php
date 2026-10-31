<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use App\Services\TranslationManagementService;

final class TranslationManagementServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-lang-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** Service réel sur un dossier de traductions temporaire (jamais le lang/ de l'application). */
    private function makeService(): TranslationManagementService
    {
        $translator = new Translator(new FileLoader(new Filesystem(), $this->dir), 'en');

        return new TranslationManagementService($translator, $this->dir);
    }
    public function testBrowseKeysReturnsValueAndReference(): void
    {
        $svc = $this->makeService();
        $svc->saveValue('fr', 'hello', 'Bonjour');
        $svc->saveValue('en', 'hello', 'Hello');

        $result = $svc->browseKeys('fr');
        $items = array_column($result['items'], null, 'key');

        $this->assertSame('Bonjour', $items['hello']['value']);
        $this->assertSame('Hello', $items['hello']['reference']);
    }

    public function testBrowseKeysReturnsEmptyValueWhenLocaleMissingKey(): void
    {
        $svc = $this->makeService();
        $svc->saveValue('en', 'hello', 'Hello');

        $result = $svc->browseKeys('fr');
        $items = array_column($result['items'], null, 'key');

        $this->assertSame('', $items['hello']['value']);
        $this->assertSame('Hello', $items['hello']['reference']);
    }

    public function testBrowseKeysFiltersBySearch(): void
    {
        $svc = $this->makeService();
        $svc->saveValue('fr', 'hello', 'Bonjour');
        $svc->saveValue('fr', 'bye', 'Au revoir');

        $result = $svc->browseKeys('fr', 'bye');
        $this->assertCount(1, $result['items']);
        $this->assertSame('bye', $result['items'][0]['key']);
    }

    public function testBrowseKeysPaginates(): void
    {
        $svc = $this->makeService();
        $svc->saveValue('fr', 'hello', 'Bonjour');
        $svc->saveValue('fr', 'bye', 'Au revoir');

        $result = $svc->browseKeys('fr', '', 1, 1);

        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['items']);
    }

    public function testSaveValueThenDeleteValueRemovesIt(): void
    {
        $svc = $this->makeService();
        $svc->saveValue('fr', 'hello', 'Bonjour');

        $this->assertSame(1, $svc->deleteValue('fr', 'hello'));

        $result = $svc->browseKeys('fr');
        $this->assertSame('', array_column($result['items'], null, 'key')['hello']['value'] ?? '');
    }

    public function testDeleteValueReturnsZeroWhenMissing(): void
    {
        $svc = $this->makeService();
        $this->assertSame(0, $svc->deleteValue('fr', 'unknown'));
    }
}
