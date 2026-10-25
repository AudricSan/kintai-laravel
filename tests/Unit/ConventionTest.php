<?php

declare(strict_types=1);

namespace kintai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConventionTest extends TestCase
{
    private const EXCEPTIONS = [
        'shifts-timeline-print.blade.php',  // dynamic date in @page @bottom-center
        'daily-report-pdf.php',       // mPDF requires embedded CSS
        'reports-hiring-pdf.php',     // mPDF requires embedded CSS
        'reports-resignation-pdf.php',// mPDF requires embedded CSS
        'reports-salary-pdf.php',     // mPDF requires embedded CSS
        'users-export-pdf.blade.php', // mPDF requires embedded CSS
        'reports-resignation-export-pdf.php', // mPDF requires embedded CSS
        'reports-salary-export-pdf.php',      // mPDF requires embedded CSS
    ];

    private const DOMAIN_DIRS = [
        'scheduling', 'staff', 'analytics', 'system',
    ];

    public function testNoInlineCssInWebViews(): void
    {
        $root = dirname(__DIR__, 2);
        $dirs = [];
        foreach (self::DOMAIN_DIRS as $dir) {
            $dirs[] = "$root/app/UI/View/$dir";
        }
        // Les vues déplacées dans des bundles restent soumises à la même convention.
        foreach (glob("$root/app/Bundles/*/Views", GLOB_ONLYDIR) ?: [] as $bundleViewsDir) {
            $dirs[] = $bundleViewsDir;
        }

        $violations = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (in_array($file->getBasename(), self::EXCEPTIONS, true)) {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if (preg_match('/<style[^>]*>/i', $content)) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        self::assertEmpty($violations, implode("\n", array_map(
            fn($v) => str_replace('\\', '/', $v),
            $violations
        )));
    }
}
