<?php

namespace Kintai\Bundles\Messaging\Tests;

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
    /** @var list<string> Libellés traduits par le Core (menu, titres, widget, journal, fonctionnalité, notifications). */
    private const DECLARED = [
        'Messaging', 'Messages', 'Unread messages', 'New message', 'Conversation', 'New message from :sender: :subject',
        ':sender replied in: :subject', 'Conversation started', 'Reply sent', 'Message deleted',
        'Conversation removed from a list', 'Conversation deleted', 'Participant added to a conversation',
        'Internal messaging between the members of a store: private conversations, unread counter, notifications.',
        'You are not allowed to read messages.', 'You are not allowed to send messages.', 'You are not allowed to start a conversation.',
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

    public function test_placeholders_are_kept_in_translations(): void
    {
        foreach (['fr', 'ja'] as $locale) {
            foreach ($this->translations($locale) as $key => $value) {
                preg_match_all('/:[a-z]+/', $key, $expected);
                foreach ($expected[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $value, "[{$locale}] {$key}");
                }
            }
        }
    }
}
