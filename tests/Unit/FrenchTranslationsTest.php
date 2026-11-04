<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Chaque chaîne littérale passée à __() dans app/ et resources/views doit avoir sa traduction française.
 */
class FrenchTranslationsTest extends TestCase
{
    public function test_every_literal_translation_key_is_translated_to_french(): void
    {
        $root = dirname(__DIR__, 2);
        $translations = json_decode((string) file_get_contents($root.'/lang/fr.json'), true, 512, JSON_THROW_ON_ERROR);
        $missing = [];

        foreach (['app', 'resources/views'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s', (string) file_get_contents($file->getPathname()), $matches);

                foreach ($matches[1] as $key) {
                    $key = stripcslashes($key);
                    if (! array_key_exists($key, $translations)) {
                        $missing[$key] = $file->getFilename();
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Missing keys in lang/fr.json');
    }
}
