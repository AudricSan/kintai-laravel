<?php

namespace Kintai\Bundles\Analytics\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Chaque chaîne littérale passée à __() dans le bundle, et chaque libellé que le bundle donne au Core, a sa traduction
 * française et japonaise.
 */
class TranslationsTest extends TestCase
{
    /** @var list<string> Libellés traduits par le Core ou par un service (menu, titres, widgets, journal, export, jours de la semaine, description du bundle). */
    private const DECLARED = [
        'Analytics', 'Store and employee statistics, profitability against daily sales, and a multi-store comparison.',
        'Store statistics', 'Employee report', 'Employee statistics', 'Profitability', 'Statistics',
        'Stats overview', 'Financial overview', 'HR and absenteeism',
        'Employee statistics viewed',
        'Store', 'Employee', 'Net hours', 'Estimated cost', 'Currency',
        'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
    ];

    /**
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__)."/lang/{$locale}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function literals(): array
    {
        $keys = self::DECLARED;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! preg_match('#/(src|resources/views)/.*\.php$#', $path)) {
                continue;
            }

            preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $key) {
                $keys[] = stripcslashes($key);
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_every_literal_is_translated_to_french_and_japanese(): void
    {
        $literals = $this->literals();
        $this->assertNotEmpty($literals);

        foreach (['fr', 'ja'] as $locale) {
            $missing = array_values(array_diff($literals, array_keys($this->translations($locale))));

            $this->assertSame([], $missing, "Missing keys in lang/{$locale}.json");
        }
    }

    public function test_translation_files_have_the_same_keys_and_no_empty_value(): void
    {
        $fr = $this->translations('fr');
        $ja = $this->translations('ja');

        $this->assertEqualsCanonicalizing(array_keys($fr), array_keys($ja));
        $this->assertSame([], array_keys(array_filter($fr, fn (string $value): bool => trim($value) === '')));
        $this->assertSame([], array_keys(array_filter($ja, fn (string $value): bool => trim($value) === '')));
    }

    public function test_translated_placeholders_are_kept(): void
    {
        foreach (['fr', 'ja'] as $locale) {
            foreach ($this->translations($locale) as $key => $value) {
                preg_match_all('/:[a-z]+/', $key, $expected);
                preg_match_all('/:[a-z]+/', $value, $found);

                $this->assertEqualsCanonicalizing($expected[0], $found[0], "Placeholders differ for \"{$key}\" in lang/{$locale}.json");
            }
        }
    }

    public function test_no_translation_is_unused(): void
    {
        $used = $this->literals();

        foreach (['fr', 'ja'] as $locale) {
            $this->assertSame([], array_values(array_diff(array_keys($this->translations($locale)), $used)), "Unused keys in lang/{$locale}.json");
        }
    }
}
