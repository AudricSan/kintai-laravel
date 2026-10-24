<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Services\PushNotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PushNotificationServiceTest extends TestCase
{
    private DevicePushTokenRepositoryInterface&MockObject $tokens;

    protected function setUp(): void
    {
        $this->tokens = $this->createMock(DevicePushTokenRepositoryInterface::class);
    }

    private function enabledConfig(): array
    {
        return ['fcm' => ['enabled' => true, 'project_id' => 'kintai-test', 'credentials_path' => '']];
    }

    public function testSendToUserIsNoopWhenDisabled(): void
    {
        $this->tokens->expects($this->never())->method('findByUser');

        $service = new PushNotificationService(['fcm' => ['enabled' => false]], $this->tokens);
        $service->sendToUser(1, 'Titre', 'Corps');
    }

    public function testSendToUserIsNoopWhenNoDeviceRegistered(): void
    {
        $this->tokens->method('findByUser')->with(1)->willReturn([]);
        $this->tokens->expects($this->never())->method('touchLastUsed');

        // Aucun jeton enregistré : ne doit jamais tenter d'obtenir un token d'accès
        // (ce qui échouerait de toute façon, credentials_path vide ici).
        $service = new PushNotificationService($this->enabledConfig(), $this->tokens);
        $service->sendToUser(1, 'Titre', 'Corps');
    }

    public function testSendToUserIsNoopWhenCredentialsMissing(): void
    {
        $this->tokens->method('findByUser')->with(1)->willReturn([
            ['id' => 10, 'user_id' => 1, 'token' => 'device-token-abc'],
        ]);
        $this->tokens->expects($this->never())->method('touchLastUsed');
        $this->tokens->expects($this->never())->method('deleteByToken');

        // credentials_path vide → loadCredentials() échoue → sendToUser() s'arrête
        // avant tout appel réseau (le transport ne doit jamais être invoqué).
        $transportCalled = false;
        $transport = function () use (&$transportCalled) {
            $transportCalled = true;
            return ['status' => 200, 'body' => '{}'];
        };

        $service = new PushNotificationService($this->enabledConfig(), $this->tokens, $transport);
        $service->sendToUser(1, 'Titre', 'Corps');

        $this->assertFalse($transportCalled);
    }

    public function testSendToUserDeletesTokenOnUnregisteredResponse(): void
    {
        $credentials = $this->writeFakeCredentials();

        $this->tokens->method('findByUser')->with(1)->willReturn([
            ['id' => 10, 'user_id' => 1, 'token' => 'stale-device-token'],
        ]);
        $this->tokens->expects($this->once())->method('deleteByToken')->with('stale-device-token');
        $this->tokens->expects($this->never())->method('touchLastUsed');

        $calls = [];
        $transport = function (string $url, array $headers, string $body) use (&$calls) {
            $calls[] = $url;
            if (str_contains($url, 'oauth2.googleapis.com')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'fake-access-token', 'expires_in' => 3600])];
            }
            return ['status' => 404, 'body' => json_encode(['error' => ['status' => 'UNREGISTERED']])];
        };

        $config = $this->enabledConfig();
        $config['fcm']['credentials_path'] = $credentials;
        $service = new PushNotificationService($config, $this->tokens, $transport);
        $service->sendToUser(1, 'Titre', 'Corps');

        $this->assertCount(2, $calls); // 1 échange de token + 1 envoi FCM

        @unlink($credentials);
    }

    public function testSendToUserTouchesLastUsedOnSuccess(): void
    {
        $credentials = $this->writeFakeCredentials();

        $this->tokens->method('findByUser')->with(1)->willReturn([
            ['id' => 10, 'user_id' => 1, 'token' => 'valid-device-token'],
        ]);
        $this->tokens->expects($this->once())->method('touchLastUsed')->with(10);
        $this->tokens->expects($this->never())->method('deleteByToken');

        $transport = function (string $url) {
            if (str_contains($url, 'oauth2.googleapis.com')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'fake-access-token', 'expires_in' => 3600])];
            }
            return ['status' => 200, 'body' => json_encode(['name' => 'projects/kintai-test/messages/0'])];
        };

        $config = $this->enabledConfig();
        $config['fcm']['credentials_path'] = $credentials;
        $service = new PushNotificationService($config, $this->tokens, $transport);
        $service->sendToUser(1, 'Titre', 'Corps');

        @unlink($credentials);
    }

    public function testLegacyWebDevicesAreIgnored(): void
    {
        // Les navigateurs ont leur propre canal (WebPushService) : une ancienne ligne « web » ne reçoit plus rien.
        $credentials = $this->writeFakeCredentials();
        $this->tokens->method('findByUser')->with(1)->willReturn([
            ['id' => 10, 'user_id' => 1, 'token' => 'old-web-token', 'platform' => 'web'],
        ]);
        $this->tokens->expects($this->never())->method('touchLastUsed');
        $this->tokens->expects($this->never())->method('deleteByToken');

        $calls = [];
        $transport = function (string $url) use (&$calls) {
            $calls[] = $url;
            return ['status' => 200, 'body' => '{}'];
        };
        $config = $this->enabledConfig();
        $config['fcm']['credentials_path'] = $credentials;

        (new PushNotificationService($config, $this->tokens, $transport))->sendToUser(1, 'Titre', 'Corps');

        $this->assertSame([], $calls);
        @unlink($credentials);
    }

    /**
     * Clé RSA de test statique (jamais transmise à un vrai serveur Google, le
     * transport est mocké) — générer une clé à la volée via openssl_pkey_new()
     * échoue sur les environnements sans openssl.cnf accessible (ex. XAMPP/Windows),
     * alors qu'openssl_sign() avec une clé PEM déjà existante fonctionne partout.
     */
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCsjmSzPhwWZZSx
    0bbjwf7A711qbiaTW3qGn2xNQHiXHr7iO9a71Olkif1UEWCf/KsjBe7tpJSemRsC
    2wXMOlFDCTsiYV7pxUVJaegC57AdwY4Ta1RdwO2CaAsOLdxKm1sC/lnxQ90A6hJE
    FPnzLiexgWEns0U4HFaGIshG5XVmbyroasfKN92GjseAhVUoT1lGOjyQac3BPrEi
    YnA/+SMx0y/EcFJTjS9eaI2VW4w28OorElFPw5DaWdajOvcTc3LHt10LCjI5FAXf
    PUG2YBveDWyhiL8y2/85RR7E1o3uHu1+CbrmIlUI2gaemBLdgWO/dEh69W8NqCYl
    hlaY6Gb5AgMBAAECggEAB8Q99JJcVca7L1i61FwAMSNk7zwneNTyeho1V/HJq7Wa
    zlh2pQwjeB682/qPQIwxELm85A3XEZ9fB50fkO5kB3IkKvs6eCeko3YElxLiCqjS
    Uf3v9WtQVWEE9F2sj2AYM9WKa1FMYnTmnxFZobAnYbYqzwxi1nByFYX9wTElPFe8
    GpFXobmJMWR4OyUkbaJvYkabXw1XSSMYhdHoyXw7OZ69amvF0QhuikXMsC+QhtBf
    uB8gln521X2hpqGWm5qlU/2CAxBRmsW2ILLGJiLnxuTkkM9ESLeDvzmRXgINoZaV
    isX1M4XNicMpsEhNExNTKkR7kc09jcouKcLpOylr/QKBgQDjSP/8YUySw5UkaeWN
    q3QVR9uXSdjklKHMwzMqhbyZFw1oj4dK5VesBcp6BvZy6KHLCjrpsCUXIkW8gUFw
    H7kOxwWiQv6KdSANJzz507oTrK7VfEAyVqXRDQyeVH8rUfFnar3CTGApKrDRaoG2
    93hFmcoBr2m7q7iNYPz3+DAg5QKBgQDCW1DT89m/GxffIpF/FVTaRMxU5aZiDH2B
    NAkkUtmP9DLjVVotvfz3CYZom9i+7YG8u+DIAvxUMAVsO20KqAcdwi2u1408RZXc
    Fj0Wnqr8hAT28ODrHMFYswRbPneoav6J+LJRIASDxwPJAao8GhK68Qml3gawwS+g
    wdb6xeUQhQKBgFH08NnA/Cuv+we2Z+A+Aw3pa3WSW3ORZQbBHKIot2k8tskNeGu5
    Z3PQYsK94ABvgmgEuFmr+rPs19ixgzc7OS/q9E0ee0rSEUys6X/sqRyPGDxDIaMF
    O6W2XuZ48aJdWf9Arkxx3fr6OehJz5x6gBQY8I7LAgV6VoIkhxOjmzdBAoGBAI5t
    EIxiFF2BYzr3QBwa67WP2RUVvZn4gThfg5uEwz5Eu83wTEddBLWb201pd6pirkI6
    g/zOg07GahLocX3vqFdcZtHL0AotDCbefSHIYJDvxhuYZZql1eJEPZsH6fQXhDRj
    dXkRt31CKDny6Gdmy/cGkAVm8QwyZc6uffYDc1tpAoGBAK5Qy0yQuTQ8gMqPuwY4
    c2ix0rusxKvTFRiKpiZVDlUuHIpy1O5Nnv+x+g6klMOZW4KTWw1tmQamnLIaa1/4
    bCpC4C/7mzQTnMBNkkohBio8CgJBnrKAZTe9tPs4I8kkQTLdJSXVGoY2KqxnIq+p
    4+pjqHvamMfYBH08UxiuSZXY
    -----END PRIVATE KEY-----
    PEM;

    private function writeFakeCredentials(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kintai-fcm-') . '.json';
        file_put_contents($path, json_encode([
            'client_email' => 'test@kintai-test.iam.gserviceaccount.com',
            'private_key'  => self::TEST_PRIVATE_KEY_PEM,
        ]));
        return $path;
    }
}
