<?php

namespace Kintai\Bundles\PlanningCheck\Tests;

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
    /** @var list<string> Libellés traduits par le Core (menu, titres, journal, fonctionnalité, notifications, états). */
    private const DECLARED = [
        'Schedule validation', "Tomorrow's schedule", 'Schedule to validate', 'Schedule validated', 'Schedule refused',
        "Tomorrow's schedule not submitted", 'Schedule sending failed',
        'To validate', 'Validated', 'Refused', 'Late', 'Pending',
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
        'Schedule submitted', 'Schedule overdue', 'Schedule sent by email', 'Schedule sent again by email', 'Schedule email failed',
        'Schedule check reopened', 'Schedule validation settings updated', 'Schedule test email sent',
        "Before the deadline, the manager confirms tomorrow's shifts; once validated, the PDF is emailed.",
        'The schedule of :date (:store) is submitted and waiting for your validation.',
        'The schedule of :date (:store) is validated and sent to the recipients.',
        'The schedule of :date (:store) was refused: :reason',
        'The schedule of :date (:store) was not submitted by the deadline.',
        'The PDF of the schedule of :date (:store) could not be sent: try again from the history.',
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
}
