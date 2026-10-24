<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * kintai_load_env_file() : chargeur .env partagé par le front controller et les scripts en ligne de commande
 * (scripts/cron.php) — un seul fichier, par priorité, sans écraser l'environnement existant.
 */
final class LoadEnvFileHelperTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-env-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
        foreach (['KINTAI_ENV_TEST_A', 'KINTAI_ENV_TEST_B', 'KINTAI_ENV_TEST_KEPT'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    public function testLoadsTheFirstFileByPriorityWithoutOverridingTheEnvironment(): void
    {
        file_put_contents($this->dir . '/.env', "KINTAI_ENV_TEST_B=generique\n");
        file_put_contents($this->dir . '/.env.local', "# commentaire\nKINTAI_ENV_TEST_A=\"valeur locale\"\nKINTAI_ENV_TEST_KEPT=fichier\n\nligne sans egal\n");
        $_ENV['KINTAI_ENV_TEST_KEPT'] = 'systeme';

        kintai_load_env_file($this->dir);

        $this->assertSame('valeur locale', $_ENV['KINTAI_ENV_TEST_A']);
        $this->assertSame('valeur locale', getenv('KINTAI_ENV_TEST_A'));
        $this->assertSame('systeme', $_ENV['KINTAI_ENV_TEST_KEPT'], "l'environnement existant n'est pas écrasé");
        $this->assertArrayNotHasKey('KINTAI_ENV_TEST_B', $_ENV, 'un seul fichier chargé (.env.local avant .env)');
    }

    public function testNoFileIsFine(): void
    {
        kintai_load_env_file($this->dir);

        $this->assertArrayNotHasKey('KINTAI_ENV_TEST_A', $_ENV);
    }
}
