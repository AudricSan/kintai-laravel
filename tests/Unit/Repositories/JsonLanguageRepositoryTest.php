<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\JsonLanguageRepository;

final class JsonLanguageRepositoryTest extends TestCase
{
    private string $langPath;
    private JsonLanguageRepository $repo;

    protected function setUp(): void
    {
        $this->langPath = sys_get_temp_dir() . '/kintai_langrepo_' . uniqid();
        mkdir($this->langPath, 0755, true);
        $this->repo = new JsonLanguageRepository($this->langPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->langPath . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->langPath);
    }

    public function testSaveCreatesNewLanguageAndTranslationFile(): void
    {
        $saved = $this->repo->save(['code' => 'es', 'name' => 'Español', 'is_active' => true, 'is_default' => false]);

        $this->assertSame('es', $saved['code']);
        $this->assertFileExists($this->langPath . '/es.json');
        $this->assertSame([], json_decode((string) file_get_contents($this->langPath . '/es.json'), true));
    }

    public function testSaveUpdatesExistingLanguageWithoutDuplicating(): void
    {
        $this->repo->save(['code' => 'es', 'name' => 'Español']);
        $this->repo->save(['code' => 'es', 'name' => 'Castellano']);

        $this->assertCount(1, $this->repo->findAll());
        $this->assertSame('Castellano', $this->repo->findByCode('es')['name']);
    }

    public function testSaveDoesNotOverwriteExistingTranslationFile(): void
    {
        $this->repo->save(['code' => 'es', 'name' => 'Español']);
        file_put_contents($this->langPath . '/es.json', json_encode(['hello' => 'Hola']));

        $this->repo->save(['code' => 'es', 'name' => 'Castellano']);

        $this->assertSame(['hello' => 'Hola'], json_decode((string) file_get_contents($this->langPath . '/es.json'), true));
    }

    public function testFindAllOrdersBySortOrder(): void
    {
        $this->repo->save(['code' => 'en', 'name' => 'English', 'sort_order' => 1]);
        $this->repo->save(['code' => 'fr', 'name' => 'Français', 'sort_order' => 0]);

        $codes = array_column($this->repo->findAll(), 'code');
        $this->assertSame(['fr', 'en'], $codes);
    }

    public function testFindAllActiveExcludesInactive(): void
    {
        $this->repo->save(['code' => 'fr', 'name' => 'Français', 'is_active' => true]);
        $this->repo->save(['code' => 'zz', 'name' => 'Inactive', 'is_active' => false]);

        $active = $this->repo->findAllActive();
        $this->assertCount(1, $active);
        $this->assertSame('fr', $active[0]['code']);
    }

    public function testFindByCodeReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findByCode('xx'));
    }

    public function testFindDefaultReturnsDefaultLanguage(): void
    {
        $this->repo->save(['code' => 'fr', 'name' => 'Français', 'is_default' => true]);
        $this->repo->save(['code' => 'en', 'name' => 'English', 'is_default' => false]);

        $this->assertSame('fr', $this->repo->findDefault()['code']);
    }

    public function testSetDefaultSwitchesDefaultLanguage(): void
    {
        $this->repo->save(['code' => 'fr', 'name' => 'Français', 'is_default' => true]);
        $this->repo->save(['code' => 'en', 'name' => 'English', 'is_default' => false]);

        $this->assertTrue($this->repo->setDefault('en'));

        $this->assertFalse((bool) $this->repo->findByCode('fr')['is_default']);
        $this->assertTrue((bool) $this->repo->findByCode('en')['is_default']);
    }

    public function testSetDefaultReturnsFalseWhenLanguageMissing(): void
    {
        $this->assertFalse($this->repo->setDefault('xx'));
    }

    public function testToggleActiveUpdatesFlag(): void
    {
        $this->repo->save(['code' => 'fr', 'name' => 'Français', 'is_active' => true]);
        $this->repo->toggleActive('fr', false);

        $this->assertFalse((bool) $this->repo->findByCode('fr')['is_active']);
    }

    public function testDeleteRemovesLanguageAndItsTranslationFile(): void
    {
        $this->repo->save(['code' => 'es', 'name' => 'Español']);
        $this->assertFileExists($this->langPath . '/es.json');

        $result = $this->repo->delete('es');

        $this->assertSame(1, $result);
        $this->assertNull($this->repo->findByCode('es'));
        $this->assertFileDoesNotExist($this->langPath . '/es.json');
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete('xx'));
    }
}
