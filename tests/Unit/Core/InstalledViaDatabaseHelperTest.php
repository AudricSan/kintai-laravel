<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * kintai_installed_via_database() est le filet de sécurité utilisé par public/index.php et
 * public/install.php quand storage/installed.lock a disparu (marqueur hors Git, cause externe
 * non identifiée — voir CHANGELOG) : il évite de forcer un réinstall destructeur tant que la
 * base de données prouve elle-même qu'une installation existe déjà.
 */
final class InstalledViaDatabaseHelperTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_installed_check_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);
        mkdir($this->tmpDir . '/storage/app', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
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

    /** @param array<string, string> $env variables DB_* du fichier .env */
    private function writeConfig(array $env): void
    {
        $lines = '';
        foreach ($env as $key => $value) {
            $lines .= $key . '=' . $value . "\n";
        }
        file_put_contents($this->tmpDir . '/.env', $lines);
    }

    public function testReturnsFalseWhenConfigFileMissing(): void
    {
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseWhenSqliteFileMissing(): void
    {
        $this->writeConfig(['DB_CONNECTION' => 'sqlite']);
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseWhenUsersTableEmpty(): void
    {
        $this->writeConfig(['DB_CONNECTION' => 'sqlite']);
        $sqlitePath = $this->tmpDir . '/storage/app/database.sqlite';
        touch($sqlitePath);
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');

        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsTrueWhenUsersTableHasARow(): void
    {
        $this->writeConfig(['DB_CONNECTION' => 'sqlite']);
        $sqlitePath = $this->tmpDir . '/storage/app/database.sqlite';
        touch($sqlitePath);
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users (id) VALUES (1)');

        $this->assertTrue(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseForUnknownDriver(): void
    {
        $this->writeConfig(['DB_CONNECTION' => 'postgres']);
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseWhenMysqlIsUnreachable(): void
    {
        $this->writeConfig(['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'kintai']);
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }
}
