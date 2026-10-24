<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Install;

use kintai\Core\Install\InstallFailure;
use kintai\Core\Install\InstallInput;
use kintai\Core\Install\WebInstaller;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Annulation de WebInstaller sur les échecs qui surviennent avant le démarrage de l'application (le parcours complet,
 * migrations comprises, est couvert par scripts/ci/install-smoke-test.sh) : rien de ce que l'installateur a écrit ne
 * doit rester, et rien de ce qui existait avant ne doit être perdu.
 */
final class WebInstallerTest extends TestCase
{
    private string $base;
    private string|false $previousLocale;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/kintai_installer_' . uniqid();
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/storage/app', 0777, true);
        $this->previousLocale = getenv('APP_LOCALE');
    }

    protected function tearDown(): void
    {
        // EnvFileWriter::set() modifie aussi l'environnement du processus.
        if ($this->previousLocale === false) {
            putenv('APP_LOCALE');
            unset($_ENV['APP_LOCALE'], $_SERVER['APP_LOCALE']);
        } else {
            putenv('APP_LOCALE=' . $this->previousLocale);
            $_ENV['APP_LOCALE'] = $_SERVER['APP_LOCALE'] = $this->previousLocale;
        }
        $this->removeDir($this->base);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function input(string $driver = 'sqlite'): InstallInput
    {
        return InstallInput::fromPost([
            'admin_first_name' => 'Aki', 'admin_last_name' => 'Sato', 'admin_email' => 'aki@example.com',
            'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
            'app_locale' => 'ja', 'db_driver' => $driver,
        ], 'en');
    }

    private function install(): InstallFailure
    {
        try {
            (new WebInstaller($this->base))->install($this->input(), null);
        } catch (InstallFailure $e) {
            return $e;
        }
        $this->fail('L\'installation aurait dû échouer');
    }

    public function testNonEmptySqliteDatabaseIsRefusedAndLeftUntouched(): void
    {
        $sqlite = $this->base . '/' . WebInstaller::SQLITE_PATH;
        $pdo = new PDO('sqlite:' . $sqlite);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users (id) VALUES (1)');
        $pdo = null;
        file_put_contents($this->base . '/.env', "APP_NAME=Kintai\nAPP_LOCALE=fr\n");

        $failure = $this->install();

        $this->assertSame('install_error_database_not_empty', $failure->messageKey);
        $this->assertSame(['name' => WebInstaller::SQLITE_PATH], $failure->replace);
        $check = new PDO('sqlite:' . $sqlite);
        $this->assertSame(1, (int) $check->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $check = null;
        // Fichier d'environnement remis tel qu'il était (la langue avait déjà été écrite).
        $this->assertSame("APP_NAME=Kintai\nAPP_LOCALE=fr\n", file_get_contents($this->base . '/.env'));
    }

    public function testFailureAfterCreatingTheDatabaseRemovesEverythingItWrote(): void
    {
        // config/ remplacé par un fichier : l'écriture de config/database.local.php échoue, après la création de la base.
        rmdir($this->base . '/config');
        file_put_contents($this->base . '/config', '');

        $failure = $this->install();

        $this->assertSame('install_error_not_writable', $failure->messageKey);
        $this->assertSame(['name' => 'config/database.local.php'], $failure->replace);
        $this->assertFileDoesNotExist($this->base . '/' . WebInstaller::SQLITE_PATH);
        $this->assertFileDoesNotExist($this->base . '/.env');
        $this->assertFileDoesNotExist($this->base . '/storage/installed.lock');
    }

    public function testEmptyLeftoverSqliteFileIsAcceptedAndEmptiedAgainOnFailure(): void
    {
        $sqlite = $this->base . '/' . WebInstaller::SQLITE_PATH;
        touch($sqlite);
        rmdir($this->base . '/config');
        file_put_contents($this->base . '/config', '');

        $this->install();

        $this->assertFileExists($sqlite);
        $this->assertSame(0, filesize($sqlite));
    }

    public function testFailureIsLoggedWithTheTechnicalDetail(): void
    {
        rmdir($this->base . '/config');
        file_put_contents($this->base . '/config', '');

        $this->install();

        $log = (string) file_get_contents($this->base . '/storage/logs/error.log');
        $this->assertStringContainsString('Installation —', $log);
        $this->assertStringContainsString('install_error_not_writable', $log);
    }
}
