<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use kintai\Core\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Un seul coût de hachage partout : le changement depuis le profil utilisait le coût 10, le reste le coût 12, et le
 * calcul factice de la connexion (coût 12) ne masquait donc plus le temps de réponse de ces comptes.
 */
final class PasswordHasherTest extends TestCase
{
    public function testHashesWithBcryptAtTheSharedCost(): void
    {
        $password = bin2hex(random_bytes(8));
        $hash = PasswordHasher::hash($password);

        $info = password_get_info($hash);
        $this->assertSame(PASSWORD_BCRYPT, $info['algo']);
        $this->assertSame(PasswordHasher::COST, $info['options']['cost']);
        $this->assertTrue(password_verify($password, $hash));
    }

    public function testNoOtherCodeHashesPasswordsOnItsOwn(): void
    {
        // Seuls PasswordHasher et le calcul factice de la connexion (AuthService, même coût) appellent password_hash().
        $allowed = ['app/Core/Auth/PasswordHasher.php', 'app/Core/Auth/AuthService.php'];
        $base = dirname(__DIR__, 3);
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ([...iterator_to_array($files), new \SplFileInfo($base . '/public/install.php')] as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            if (preg_match('/\bpassword_hash\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testTheLoginTimingGuardUsesTheSameCost(): void
    {
        $constant = new \ReflectionClassConstant(\kintai\Core\Auth\AuthService::class, 'TIMING_COST');

        $this->assertSame(PasswordHasher::COST, $constant->getValue());
    }
}
