<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\TranslationManagementService;
use kintai\Core\Repositories\TranslationRepositoryInterface;

final class TranslationManagementServiceTest extends TestCase
{
    public array $data = [];

    protected function tearDown(): void
    {
        $this->data = [];
    }

    private function makeService(): TranslationManagementService
    {
        $repo = new class($this) implements TranslationRepositoryInterface {
            public function __construct(private TranslationManagementServiceTest $test) {}
            public function findByLocale(string $locale): array { return $this->test->data[$locale] ?? []; }
            public function findValue(string $locale, string $key): ?string { return $this->test->data[$locale][$key] ?? null; }
            public function findAllKeys(): array
            {
                $keys = [];
                foreach ($this->test->data as $values) {
                    $keys = array_merge($keys, array_keys($values));
                }
                return array_values(array_unique($keys));
            }
            public function save(string $locale, string $key, string $value): void
            {
                $this->test->data[$locale][$key] = $value;
            }
            public function delete(string $locale, string $key): int
            {
                if (!isset($this->test->data[$locale][$key])) return 0;
                unset($this->test->data[$locale][$key]);
                return 1;
            }
            public function countByLocale(string $locale): int { return count($this->test->data[$locale] ?? []); }
        };

        return new TranslationManagementService($repo);
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
