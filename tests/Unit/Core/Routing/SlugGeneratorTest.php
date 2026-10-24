<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Routing;

use kintai\Core\Routing\SlugGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Alias d'URL des magasins : jamais de translittération (les kanji seraient lus en mandarin). */
final class SlugGeneratorTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'japonais conservé tel quel'  => ['所沢東町店', '所沢東町店'],
            'kana conservés'              => ['所沢くすのき台店', '所沢くすのき台店'],
            'ponctuation japonaise'       => ['所沢寿町店（新）', '所沢寿町店-新'],
            'latin en minuscules'         => ['Shibuya Nord', 'shibuya-nord'],
            'accents conservés'           => ['Café Été', 'café-été'],
            'caractères qui cassent une URL' => ['A/B ? C#D %E \\F', 'a-b-c-d-e-f'],
            'blancs multiples'            => ["  Tokyo   Station \t", 'tokyo-station'],
            'numérique seul'              => ['7', 'store-7'],
            'que de la ponctuation'       => ['?!', ''],
        ];
    }

    #[DataProvider('names')]
    public function testFromNameKeepsTheNameReadableWithoutTransliteration(string $name, string $expected): void
    {
        $this->assertSame($expected, SlugGenerator::fromName($name));
    }

    public function testFromNameIsCappedInLength(): void
    {
        $slug = SlugGenerator::fromName(str_repeat('店', 100));
        $this->assertSame(SlugGenerator::MAX_LENGTH, mb_strlen($slug));
    }

    /** @return array<string, array{string, bool}> */
    public static function manualSlugs(): array
    {
        return [
            'romaji'               => ['tokorozawa-higashicho', true],
            'avec chiffres'        => ['store-2', true],
            'majuscules'           => ['Tokorozawa', false],
            'espace'               => ['tokorozawa higashi', false],
            'double tiret'         => ['a--b', false],
            'tiret final'          => ['abc-', false],
            'japonais'             => ['所沢', false],
            'uniquement numérique' => ['123', false],
            'numérique avec tiret' => ['12-34', false],
            'trop long'            => [str_repeat('a', 61), false],
        ];
    }

    #[DataProvider('manualSlugs')]
    public function testManualSlugFormat(string $slug, bool $valid): void
    {
        $this->assertSame($valid, SlugGenerator::isValidManual($slug));
    }

    public function testFirstFreeAddsANumericSuffix(): void
    {
        $taken = ['shibuya', 'shibuya-2'];
        $this->assertSame('shibuya-3', SlugGenerator::firstFree('shibuya', fn(string $c) => in_array($c, $taken, true)));
        $this->assertSame('ebisu', SlugGenerator::firstFree('ebisu', fn(string $c) => in_array($c, $taken, true)));
    }
}
