<?php

namespace Tests\Feature\Push;

use App\Push\WebPushEndpointValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebPushEndpointValidatorTest extends TestCase
{
    /**
     * @param  list<string>  $ips
     */
    private function validator(array $ips = ['93.184.216.34']): WebPushEndpointValidator
    {
        return new WebPushEndpointValidator(fn (): array => $ips);
    }

    public function test_a_public_https_push_service_is_accepted(): void
    {
        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc',
            'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'https://web.push.apple.com/abc',
            'https://fcm.googleapis.com:443/fcm/send/abc',
        ] as $endpoint) {
            $this->assertTrue($this->validator()->isAcceptable($endpoint), $endpoint);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rejectedEndpoints(): array
    {
        return [
            'empty' => [''],
            'http' => ['http://fcm.googleapis.com/fcm/send/abc'],
            'other scheme' => ['ftp://fcm.googleapis.com/x'],
            'javascript' => ['javascript:alert(1)'],
            'no scheme' => ['fcm.googleapis.com/fcm/send/abc'],
            'credentials' => ['https://user:pass@fcm.googleapis.com/x'],
            'odd port' => ['https://fcm.googleapis.com:8443/x'],
            'localhost' => ['https://localhost/x'],
            'localhost subdomain' => ['https://app.localhost/x'],
            'single label' => ['https://intranet/x'],
            'internal suffix' => ['https://push.internal/x'],
            'test suffix' => ['https://push.test/x'],
            'ipv4 literal' => ['https://93.184.216.34/x'],
            'loopback literal' => ['https://127.0.0.1/x'],
            'private literal' => ['https://10.0.0.5/x'],
            'ipv6 literal' => ['https://[::1]/x'],
            'too long' => ['https://fcm.googleapis.com/'.str_repeat('a', 2000)],
        ];
    }

    #[DataProvider('rejectedEndpoints')]
    public function test_unsafe_endpoints_are_rejected(string $endpoint): void
    {
        $this->assertFalse($this->validator()->isAcceptable($endpoint));
    }

    public function test_a_name_resolving_to_a_private_or_reserved_address_is_rejected(): void
    {
        foreach (['10.0.0.5', '192.168.1.10', '172.16.0.1', '127.0.0.1', '169.254.169.254', '::1', 'fd00::1', 'fe80::1', '64:ff9b::7f00:1', '2002:7f00:1::1'] as $ip) {
            $this->assertFalse($this->validator([$ip])->isAcceptable('https://push.example.org/x'), $ip);
        }
    }

    public function test_one_internal_address_among_public_ones_is_enough_to_reject(): void
    {
        $this->assertFalse($this->validator(['93.184.216.34', '10.0.0.5'])->isAcceptable('https://push.example.org/x'));
    }

    public function test_a_name_that_does_not_resolve_is_rejected(): void
    {
        $this->assertFalse($this->validator([])->isAcceptable('https://push.example.org/x'));
    }

    public function test_public_ip_detection(): void
    {
        $this->assertTrue(WebPushEndpointValidator::isPublicIp('93.184.216.34'));
        $this->assertTrue(WebPushEndpointValidator::isPublicIp('2606:2800:220:1:248:1893:25c8:1946'));
        $this->assertFalse(WebPushEndpointValidator::isPublicIp('10.1.2.3'));
        $this->assertFalse(WebPushEndpointValidator::isPublicIp('not-an-ip'));
    }
}
