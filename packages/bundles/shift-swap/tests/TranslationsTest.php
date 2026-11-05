<?php

namespace Kintai\Bundles\ShiftSwap\Tests;

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
        'Shift swaps', 'Swap requests', 'Swaps to approve', 'Swaps awaiting my answer', 'Request a swap',
        'Shift swaps: an employee proposes a swap to a colleague, a manager approves it and the schedule is updated.',
        'Waiting for colleague', 'Waiting for manager', 'Applied', 'Refused', 'Cancelled', 'Pending',
        'Swap request', 'Swap accepted by colleague', 'Swap declined by colleague', 'Swap to approve', 'Swap approved', 'Swap refused',
        'Swap cancelled', 'Swap applied by a manager', 'Swap undone, shifts restored',
        'Swap requested', 'Swap updated', 'Swap deleted',
        ':colleague offers a shift swap: their shift :from against your shift :to (:store).',
        ':colleague accepted the swap of your shift :from against :to (:store). It now waits for a manager.',
        ':colleague declined the swap of your shift :from against :to (:store).',
        ':colleague accepted a shift swap (:from against :to, :store). Your decision is needed.',
        ':colleague cancelled the swap request (:from against :to, :store).',
        'The shift swap (:from against :to, :store) was approved and applied to your schedule.',
        'The shift swap request (:from against :to, :store) was refused.',
        'A manager applied a shift swap to your schedule: :from against :to (:store).',
        'The shift swap (:from against :to, :store) was cancelled and your shifts were restored.',
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
