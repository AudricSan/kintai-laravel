<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

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

    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->tmpDir . '/config/database.local.php',
            '<?php return ' . var_export($config, true) . ';' . PHP_EOL
        );
    }

    public function testReturnsFalseWhenConfigFileMissing(): void
    {
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseWhenSqliteFileMissing(): void
    {
        $this->writeConfig(['driver' => 'sqlite', 'connections' => []]);
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseWhenUsersTableEmpty(): void
    {
        $this->writeConfig(['driver' => 'sqlite', 'connections' => []]);
        $sqlitePath = $this->tmpDir . '/storage/app/database.sqlite';
        touch($sqlitePath);
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');

        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsTrueWhenUsersTableHasARow(): void
    {
        $this->writeConfig(['driver' => 'sqlite', 'connections' => []]);
        $sqlitePath = $this->tmpDir . '/storage/app/database.sqlite';
        touch($sqlitePath);
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users (id) VALUES (1)');

        $this->assertTrue(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseForUnknownDriver(): void
    {
        $this->writeConfig(['driver' => 'postgres', 'connections' => []]);
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }

    public function testReturnsFalseForMysqlWithoutConnectionConfig(): void
    {
        $this->writeConfig(['driver' => 'mysql', 'connections' => []]);
        $this->assertFalse(kintai_installed_via_database($this->tmpDir));
    }
}
