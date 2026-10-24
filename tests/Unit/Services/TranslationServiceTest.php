<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\TranslationService;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;

final class TranslationServiceTest extends TestCase
{
    /**
     * @param array<string,array<string,string>> $translations key: locale => (key => value)
     */
    private function makeService(array $translations = [], string $defaultCode = 'en'): TranslationService
    {
        $translationRepository = new class($translations) implements TranslationRepositoryInterface {
            public function __construct(private array $translations) {}
            public function findByLocale(string $locale): array { return $this->translations[$locale] ?? []; }
            public function findValue(string $locale, string $key): ?string { return $this->translations[$locale][$key] ?? null; }
            public function findAllKeys(): array
            {
                $keys = [];
                foreach ($this->translations as $values) {
                    $keys = array_merge($keys, array_keys($values));
                }
                return array_values(array_unique($keys));
            }
            public function save(string $locale, string $key, string $value): void {}
            public function delete(string $locale, string $key): int { return 0; }
            public function countByLocale(string $locale): int { return count($this->translations[$locale] ?? []); }
        };

        $languageRepository = new class($defaultCode) implements LanguageRepositoryInterface {
            public function __construct(private string $defaultCode) {}
            public function findAll(): array { return []; }
            public function findAllActive(): array { return []; }
            public function findByCode(string $code): ?array { return null; }
            public function findDefault(): ?array { return ['code' => $this->defaultCode]; }
            public function save(array $data): array { return $data; }
            public function setDefault(string $code): bool { return true; }
            public function toggleActive(string $code, bool $active): bool { return true; }
            public function delete(string $code): int { return 0; }
        };

        return new TranslationService($translationRepository, $languageRepository);
    }

    private function fixtureData(): array
    {
        return [
            'fr' => ['hello' => 'Bonjour', 'welcome' => 'Bienvenue :name !', 'count' => ':n éléments'],
            'en' => ['hello' => 'Hello', 'welcome' => 'Welcome :name!', 'count' => ':n items'],
            'ja' => ['hello' => 'こんにちは', 'welcome' => 'ようこそ :name！'],
        ];
    }

    // -------------------------------------------------------------------------
    // Locale par défaut
    // -------------------------------------------------------------------------

    public function testDefaultLocaleIsEn(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $this->assertSame('en', $svc->getLocale());
    }

    // -------------------------------------------------------------------------
    // setLocale / getLocale
    // -------------------------------------------------------------------------

    public function testSetLocaleChangesLocale(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('fr');
        $this->assertSame('fr', $svc->getLocale());
    }

    public function testSetLocaleJa(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('ja');
        $this->assertSame('ja', $svc->getLocale());
    }

    // -------------------------------------------------------------------------
    // translate()
    // -------------------------------------------------------------------------

    public function testTranslateReturnsCorrectFrenchString(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('fr');
        $this->assertSame('Bonjour', $svc->translate('hello'));
    }

    public function testTranslateReturnsCorrectEnglishString(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('en');
        $this->assertSame('Hello', $svc->translate('hello'));
    }

    public function testTranslateReturnsJapanese(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('ja');
        $this->assertSame('こんにちは', $svc->translate('hello'));
    }

    public function testTranslateMissingKeyReturnsKey(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('en');
        $this->assertSame('nonexistent.key', $svc->translate('nonexistent.key'));
    }

    public function testTranslateReplacesPlaceholder(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('en');
        $result = $svc->translate('welcome', ['name' => 'Alice']);
        $this->assertSame('Welcome Alice!', $result);
    }

    public function testTranslateFrenchPlaceholder(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('fr');
        $result = $svc->translate('welcome', ['name' => 'Bob']);
        $this->assertSame('Bienvenue Bob !', $result);
    }

    public function testTranslateMultiplePlaceholders(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('en');
        $result = $svc->translate('count', ['n' => '5']);
        $this->assertSame('5 items', $result);
    }

    public function testTranslateMissingPlaceholderLeftAsIs(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('en');
        // Pas de remplacement fourni pour :name → :name reste tel quel
        $result = $svc->translate('welcome', []);
        $this->assertStringContainsString(':name', $result);
    }

    // -------------------------------------------------------------------------
    // Fallback locale par défaut
    // -------------------------------------------------------------------------

    public function testFallbackToDefaultLocaleForUnknownLocale(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('xx'); // n'existe pas
        $this->assertSame('Hello', $svc->translate('hello'));
    }

    public function testLanguageWithPartialTranslationsFallsBackForMissingKey(): void
    {
        // 'es' n'a que quelques clés définies.
        $data = $this->fixtureData();
        $data['es'] = ['hello' => 'Hola'];
        $svc = $this->makeService($data, defaultCode: 'en');
        $svc->setLocale('es');

        $this->assertSame('Hola', $svc->translate('hello'));
        // Clé absente pour 'es' → retombe sur la langue par défaut (en)
        $this->assertSame('Welcome Alice!', $svc->translate('welcome', ['name' => 'Alice']));
    }

    // -------------------------------------------------------------------------
    // Idempotence (même locale deux fois)
    // -------------------------------------------------------------------------

    public function testSetSameLocaleTwiceDoesNotReload(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('fr');
        $svc->setLocale('fr'); // deuxième appel → pas de rechargement
        $this->assertSame('Bonjour', $svc->translate('hello'));
    }

    public function testSwitchBetweenLocales(): void
    {
        $svc = $this->makeService($this->fixtureData());
        $svc->setLocale('fr');
        $this->assertSame('Bonjour', $svc->translate('hello'));
        $svc->setLocale('en');
        $this->assertSame('Hello', $svc->translate('hello'));
    }
}
