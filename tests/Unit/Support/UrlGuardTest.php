<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Audit SEC-08 : le serveur ne suit que des adresses HTTPS publiques (pas de SSRF vers le réseau interne). */
final class UrlGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['KINTAI_HTTP_ALLOW_PRIVATE'], $_SERVER['KINTAI_HTTP_ALLOW_PRIVATE']);
        putenv('KINTAI_HTTP_ALLOW_PRIVATE');
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'boucle locale'     => ['https://127.0.0.1/x'],
            'réseau privé'      => ['https://10.0.0.5/x'],
            'réseau privé 192'  => ['https://192.168.1.10/x'],
            'métadonnées cloud' => ['https://169.254.169.254/latest/meta-data'],
            'IPv6 locale'       => ['https://[::1]/x'],
            'HTTP en clair'     => ['http://8.8.8.8/x'],
            'identifiants'      => ['https://user:pass@8.8.8.8/x'],
            'sans hôte'         => ['https:///x'],
            'autre protocole'   => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('refused')]
    public function testAnUnsafeAddressIsRefused(string $url): void
    {
        $this->assertIsString(UrlGuard::check($url), $url);
    }

    public function testAPublicHttpsAddressIsAcceptedAndPinned(): void
    {
        $result = UrlGuard::check('https://8.8.8.8:8443/path');

        $this->assertSame(['host' => '8.8.8.8', 'ip' => '8.8.8.8', 'port' => 8443], $result);
    }

    public function testThePrivateRestrictionCanBeLiftedForLocalDevelopment(): void
    {
        $_ENV['KINTAI_HTTP_ALLOW_PRIVATE'] = 'true';
        putenv('KINTAI_HTTP_ALLOW_PRIVATE=true');

        $this->assertIsArray(UrlGuard::check('http://127.0.0.1:8000/license'));
    }
}
