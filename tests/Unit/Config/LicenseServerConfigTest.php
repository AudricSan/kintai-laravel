<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/** config/license_server.php : serveur officiel par défaut, chaque valeur remplaçable dans .env. */
final class LicenseServerConfigTest extends TestCase
{
    private const KEYS = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_API_KEY', 'LICENSE_SERVER_PUBLIC_KEY_B64'];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function config(): array
    {
        return require dirname(__DIR__, 3) . '/config/license_server.php';
    }

    public function testAnEmptyEnvironmentUsesTheOfficialServer(): void
    {
        $_ENV['LICENSE_SERVER_URL'] = ''; // comme .env.example : vide = valeur officielle
        $config = $this->config();

        $this->assertStringStartsWith('https://', $config['base_url']);
        $this->assertStringStartsWith('lm_', $config['api_key']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', (string) $config['public_key_pem']);
    }

    public function testEachValueCanBeOverriddenInTheEnvironment(): void
    {
        $_ENV['LICENSE_SERVER_URL'] = 'https://licences.example.test/api/v1/';
        $_ENV['LICENSE_SERVER_API_KEY'] = 'lm_private';
        $config = $this->config();

        $this->assertSame('https://licences.example.test/api/v1', $config['base_url']);
        $this->assertSame('lm_private', $config['api_key']);
        $this->assertNotNull($config['public_key_pem'], 'clé publique non remplacée : la valeur officielle reste');
    }
}
