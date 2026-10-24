<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Middleware\SessionMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 01/10/2026 (point 6) : AuthController copiait la ligne utilisateur complète, hash du mot de passe
 * compris, dans $_SESSION['auth_user'] sans que rien ne la relise jamais (l'utilisateur est rechargé depuis la base
 * à chaque requête, AuthService::user()).
 */
final class SessionUserCopyTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLeftoverCopyIsRemovedFromOpenSessions(): void
    {
        $_SESSION['auth_user']    = ['id' => 7, 'password_hash' => '$2y$12$abc'];
        $_SESSION['auth_user_id'] = 7;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
        $_GET = $_POST = $_COOKIE = $_FILES = [];

        (new SessionMiddleware())->handle(new Request(), fn() => Response::json([]));

        $this->assertArrayNotHasKey('auth_user', $_SESSION);
        $this->assertSame(7, $_SESSION['auth_user_id'], 'la session elle-même est conservée');
    }

    public function testNoCodeWritesTheUserRowIntoTheSessionAnymore(): void
    {
        $root  = dirname(__DIR__, 3) . '/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/\$_SESSION\[\s*[\'"]auth_user[\'"]\s*\]\s*=(?!=)/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders);
    }
}
