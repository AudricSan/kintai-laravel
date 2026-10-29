<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * La page de l'installateur est incluse par son chemin depuis public/install.php, avant tout démarrage de
 * l'application : elle reste un fichier PHP simple, hors Blade.
 */
final class InstallViewTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testTheInstallerIncludesAPlainPhpViewThatRenders(): void
    {
        $root = dirname(__DIR__, 3);
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $root);
        }
        $this->assertSame(1, preg_match("#require BASE_PATH \\. '(/resources/views/[^']+)'#", (string) file_get_contents($root . '/public/install.php'), $m));
        $this->assertFileExists($root . $m[1]);

        $html = (static function (string $__file): string {
            extract([
                'BASE_URL' => '', 'locale' => 'fr', 'languages' => [], 'checks' => [], 'can_install' => true,
                'drivers' => ['sqlite'], 'errors' => [], 'result' => null, 'setup_url' => '/admin/setup',
                'demo_password' => 'x', 'old' => [], 'default_host' => '127.0.0.1', 'default_port' => '3306',
                'default_database' => 'kintai', 'default_username' => 'root',
            ]);
            ob_start();
            try {
                require $__file;
            } finally {
                $out = (string) ob_get_clean();
            }
            return $out;
        })($root . $m[1]);

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('name="db_driver"', $html);
    }
}
