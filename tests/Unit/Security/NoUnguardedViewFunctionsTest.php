<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Une vue qui déclare une fonction globale sans garde provoque une erreur fatale (« Cannot redeclare ») si elle est
 * rendue deux fois dans un même processus (audit n°8 : store-stats et store-profitability). Toute déclaration en
 * début de ligne dans app/UI/View doit donc être placée dans un bloc if (!function_exists(...)) (indentée).
 */
final class NoUnguardedViewFunctionsTest extends TestCase
{
    public function testViewsDeclareFunctionsOnlyBehindAFunctionExistsGuard(): void
    {
        $base = dirname(__DIR__, 3);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/app/UI/View', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                // Fonctions PHP seulement (type de retour) : les fonctions JavaScript des <script> en ligne ne comptent pas.
                if (preg_match('/^function\s+\w+\s*\([^)]*\)\s*:\s*\??\w/', $line)) {
                    $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1)) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'fonction de vue sans garde function_exists : ' . implode(', ', $offenders));
    }
}
