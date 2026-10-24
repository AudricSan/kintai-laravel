<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\View;

use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Un gabarit Blade n'est pas du PHP valide tel quel (la CI ne le passe pas à php -l) : on vérifie la syntaxe de ce que
 * Blade en compile, pour chaque vue du cœur.
 */
final class BladeViewsCompileTest extends TestCase
{
    public function testEveryCoreBladeViewCompilesToValidPhp(): void
    {
        $base = dirname(__DIR__, 3) . '/app/UI/View';
        $factory = (new ViewRenderer($base))->factory();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            if (!str_ends_with($relative, '.blade.php')) {
                continue;
            }
            $name = str_replace('/', '.', substr($relative, 0, -strlen('.blade.php')));
            try {
                token_get_all((string) file_get_contents(ViewRenderer::pathInScope($name, $factory)), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $this->fail("{$relative} : {$e->getMessage()} (ligne {$e->getLine()} du PHP compilé)");
            }
            $count++;
        }
        $this->assertGreaterThan(90, $count);
    }
}
