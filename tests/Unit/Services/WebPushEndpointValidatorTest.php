<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\WebPushEndpointValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Une adresse d'abonnement ne doit jamais permettre au serveur d'émettre des requêtes vers l'intérieur. */
final class WebPushEndpointValidatorTest extends TestCase
{
    /** Résolveur factice : un nom « interne » se résout vers une adresse privée, tout autre vers une adresse publique. */
    private function validator(): WebPushEndpointValidator
    {
        return new WebPushEndpointValidator(static fn(string $host): array => match (true) {
            str_starts_with($host, 'interne.') => ['10.0.0.12'],
            str_starts_with($host, 'metadata.') => ['169.254.169.254'],
            str_starts_with($host, 'mixte.') => ['142.250.74.10', '192.168.1.5'],
            str_starts_with($host, 'inconnu.') => [],
            // Un nom peut avoir une adresse IPv4 publique et une IPv6 interne : une seule suffit à le refuser.
            str_starts_with($host, 'v6interne.') => ['142.250.74.10', 'fd00::1'],
            str_starts_with($host, 'v6boucle.') => ['::1'],
            str_starts_with($host, 'v6mappee.') => ['::ffff:10.0.0.1'],
            str_starts_with($host, 'nat64.') => ['64:ff9b::a00:1'],
            str_starts_with($host, 'sixtofour.') => ['2002:a00:1::1'],
            str_starts_with($host, 'v6public.') => ['2a00:1450:4007:80f::200e'],
            default => ['142.250.74.10'],
        });
    }

    /** @return array<string, array{string}> */
    public static function realPushServices(): array
    {
        return [
            'FCM (Chrome, Edge, Brave)' => ['https://fcm.googleapis.com/fcm/send/dXNlci1wdXNoLXRva2Vu'],
            'Mozilla'                   => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAAABk'],
            'Apple'                     => ['https://web.push.apple.com/QGxGp3Wy'],
            'Windows'                   => ['https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB'],
            'port 443 explicite'        => ['https://fcm.googleapis.com:443/fcm/send/abc'],
            'IPv6 publique'             => ['https://v6public.exemple.org/x'],
        ];
    }

    #[DataProvider('realPushServices')]
    public function testAcceptsRealPushServices(string $endpoint): void
    {
        $this->assertTrue($this->validator()->isAcceptable($endpoint));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'vide'                         => [''],
            'http'                         => ['http://fcm.googleapis.com/fcm/send/abc'],
            'autre schéma'                 => ['ftp://fcm.googleapis.com/x'],
            'sans schéma'                  => ['fcm.googleapis.com/fcm/send/abc'],
            'identifiants dans l\'adresse' => ['https://user:pass@fcm.googleapis.com/x'],
            'port inhabituel'              => ['https://fcm.googleapis.com:8443/x'],
            'adresse IPv4'                 => ['https://142.250.74.10/x'],
            'IPv4 de bouclage'             => ['https://127.0.0.1/x'],
            'IPv6'                         => ['https://[::1]/x'],
            'localhost'                    => ['https://localhost/x'],
            'nom sans point'               => ['https://serveur/x'],
            'nom local'                    => ['https://imprimante.local/x'],
            'nom interne'                  => ['https://wiki.internal/x'],
            'se résout en privé'           => ['https://interne.exemple.org/x'],
            'métadonnées d\'hébergeur'     => ['https://metadata.exemple.org/latest'],
            'une adresse privée parmi d\'autres' => ['https://mixte.exemple.org/x'],
            'ne se résout pas'             => ['https://inconnu.exemple.org/x'],
            'IPv4 publique + IPv6 privée'  => ['https://v6interne.exemple.org/x'],
            'IPv6 de bouclage'             => ['https://v6boucle.exemple.org/x'],
            'IPv6 mappée sur IPv4 privée'  => ['https://v6mappee.exemple.org/x'],
            'NAT64 vers une adresse privée' => ['https://nat64.exemple.org/x'],
            '6to4'                         => ['https://sixtofour.exemple.org/x'],
            'trop longue'                  => ['https://fcm.googleapis.com/' . str_repeat('a', 2000)],
        ];
    }

    /** @return array<string, array{string, bool}> */
    public static function addresses(): array
    {
        return [
            'IPv4 publique'     => ['142.250.74.10', true],
            'IPv6 publique'     => ['2a00:1450:4007:80f::200e', true],
            'IPv4 privée'       => ['10.0.0.1', false],
            'lien local IPv4'   => ['169.254.169.254', false],
            'bouclage IPv6'     => ['::1', false],
            'non spécifiée'     => ['::', false],
            'lien local IPv6'   => ['fe80::1', false],
            'unique locale'     => ['fc00::1', false],
            'mappée privée'     => ['::ffff:192.168.0.1', false],
            'NAT64'             => ['64:ff9b::7f00:1', false],
            'NAT64 local'       => ['64:ff9b:1::1', false],
            '6to4'              => ['2002:c0a8:1::1', false],
            'Teredo'            => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', false],
            'documentation'     => ['2001:db8::1', false],
            'pas une adresse'   => ['n-importe-quoi', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        $this->assertSame($expected, WebPushEndpointValidator::isPublicIp($ip));
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingElse(string $endpoint): void
    {
        $this->assertFalse($this->validator()->isAcceptable($endpoint));
    }
}
