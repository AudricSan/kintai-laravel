<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou des fichiers lang/*.json du Core (audit du 13/10/2026 : clés affichées en brut).
 *
 * - Toutes les langues présentes dans lang/ portent exactement les mêmes clés. Les langues sont
 *   découvertes par glob : une langue ajoutée plus tard est contrôlée sans toucher au test.
 * - Toute clé littérale passée à __() dans src/ existe dans le Core (les clés construites par
 *   concaténation, ex. __('perm_category_' . $x), sont volontairement ignorées).
 */
final class CoreTranslationsCompletenessTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../../lang';

    /**
     * Clés que le Core référence mais que le bundle concerné traduit lui-même (lien de la barre
     * du haut, libellé de type de notification) : elles ne sont affichées que si le bundle est actif.
     */
    private const BUNDLE_OWNED = [];

    /** @return array<string, array<string, string>> locale => clés */
    private function locales(): array
    {
        $out = [];
        foreach (glob(self::LANG_DIR . '/*.json') ?: [] as $file) {
            $out[basename($file, '.json')] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    public function testEveryLocaleHasTheSameKeys(): void
    {
        $locales = $this->locales();
        $this->assertGreaterThanOrEqual(3, count($locales));

        $all = array_unique(array_merge(...array_values(array_map('array_keys', $locales))));
        foreach ($locales as $code => $keys) {
            $missing = array_values(array_diff($all, array_keys($keys)));
            $this->assertSame([], $missing, "Clés absentes de lang/{$code}.json : " . implode(', ', $missing));
        }
    }

    public function testEveryLiteralKeyUsedInSrcExistsInTheCore(): void
    {
        $known = $this->locales()['en'];
        $unknown = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match_all("/\b__\(\s*'([a-z0-9_.]+)'\s*[,)]/", (string) file_get_contents($file->getPathname()), $m)) {
                foreach (array_unique($m[1]) as $key) {
                    if (!isset($known[$key]) && !in_array($key, self::BUNDLE_OWNED, true)) {
                        $unknown[$key] = basename($file->getPathname());
                    }
                }
            }
        }

        $this->assertSame([], $unknown, 'Clés utilisées mais absentes de lang/en.json : ' . json_encode($unknown));
    }
}
