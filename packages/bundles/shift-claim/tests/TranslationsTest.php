<?php

namespace Kintai\Bundles\ShiftClaim\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Chaque chaîne littérale passée à __() dans le bundle, et chaque libellé que le bundle donne au Core, a sa traduction
 * française (et japonaise : le bundle en fournit une).
 */
class TranslationsTest extends TestCase
{
    /** @var list<string> Libellés traduits par le Core (menu, section du profil, titres, journal, fonctionnalité). */
    private const DECLARED = [
        'Open shifts', 'Shift applications', 'Open shift',
        'Open shifts: open and offered shifts are proposed to employees, who apply; a manager picks one and the schedule is updated.',
        'Pending', 'Approved', 'Rejected', 'Withdrawn',
        'Shift application', 'Shift application approved', 'Shift application not selected', 'Shift no longer open',
        'Shift application sent', 'Shift application withdrawn', 'Shift offered to applications', 'Shift withdrawn from applications',
        ':author applied for the shift on :date from :start to :end (:store).',
        'Your application for the shift on :date from :start to :end (:store) was approved: the shift is yours.',
        'Your application for the shift on :date from :start to :end (:store) was not selected.',
        'The shift on :date from :start to :end (:store) is no longer open for applications.',
        'A shift is open for applications: :date from :start to :end (:store).',
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
}
