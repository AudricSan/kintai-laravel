<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\HttpFetcher;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\LicenseTokenVerifier;
use kintai\Tests\Support\TestSigningKeypair;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class LicenseClientServiceTest extends TestCase
{
    /** @var array{private: string, public: string} Paire jetable, generee a la volee — voir TestSigningKeypair. */
    private static array $keypair;

    private array $store = [];
    private AppSettingsRepositoryInterface&Stub $appSettings;

    public static function setUpBeforeClass(): void
    {
        self::$keypair = TestSigningKeypair::generate();
    }

    protected function setUp(): void
    {
        $this->store = [];
        $this->appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $this->appSettings->method('get')->willReturnCallback(fn(string $k) => $this->store[$k] ?? null);
        $this->appSettings->method('set')->willReturnCallback(function (string $k, string $v): void {
            $this->store[$k] = $v;
        });
    }

    private function makeService(array $config = [], ?callable $transport = null, ?LicenseTokenVerifier $tokenVerifier = null): LicenseClientService
    {
        $config = array_merge([
            'base_url'          => 'https://license.test/api/v1',
            'api_key'           => 'kintai-product-key',
            'grace_period_days' => 14,
        ], $config);

        return new LicenseClientService(
            $this->appSettings,
            $config,
            new HttpFetcher(),
            $transport,
            $tokenVerifier ?? new LicenseTokenVerifier(null),
        );
    }

    /** Signe un token comme le ferait LicenseTokenSigner cote serveur (License Manager). */
    private function signToken(array $payload): string
    {
        $privateKey = openssl_pkey_get_private(self::$keypair['private']);
        $encode = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $encodedPayload = $encode(json_encode($payload, JSON_THROW_ON_ERROR));
        openssl_sign($encodedPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $encodedPayload . '.' . $encode($signature);
    }

    // -------------------------------------------------------------------------
    // instanceId()
    // -------------------------------------------------------------------------

    public function testInstanceIdIsGeneratedOnceAndPersisted(): void
    {
        $service = $this->makeService();

        $first  = $service->instanceId();
        $second = $service->instanceId();

        $this->assertSame($first, $second);
        $this->assertSame(32, strlen($first));
    }

    // -------------------------------------------------------------------------
    // isPaidPlanActive() — plan gratuit par défaut
    // -------------------------------------------------------------------------

    public function testIsPaidPlanActiveFalseWithoutAnyLicense(): void
    {
        $this->assertFalse($this->makeService()->isPaidPlanActive());
    }

    // -------------------------------------------------------------------------
    // activate()
    // -------------------------------------------------------------------------

    public function testActivateWithValidKeyUnlocksPaidPlan(): void
    {
        $service = $this->makeService([], fn(): string => json_encode([
            'valid' => true, 'status' => 'active', 'type' => 'yearly', 'expires_at' => '2027-01-01 00:00:00',
        ]));

        $result = $service->activate('KEY-1');

        $this->assertTrue($result['valid']);
        $this->assertTrue($service->isPaidPlanActive());
        $this->assertSame('KEY-1', $service->licenseKey());
    }

    /** issued_at/max_activations/active_activations viennent enrichir la réponse activate/validate côté serveur (License Manager) — vérifie qu'ils sont bien persistés dans l'état local pour l'affichage sur /admin/license. */
    public function testActivateStoresPlanDetailsFromServerResponse(): void
    {
        $service = $this->makeService([], fn(): string => json_encode([
            'valid' => true, 'status' => 'active', 'type' => 'yearly',
            'issued_at' => '2026-01-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00',
            'max_activations' => 3, 'active_activations' => 1,
        ]));

        $service->activate('KEY-1');
        $state = $service->state();

        $this->assertSame('2026-01-01 00:00:00', $state['issued_at']);
        $this->assertSame(3, $state['max_activations']);
        $this->assertSame(1, $state['active_activations']);
    }

    public function testRefreshDegradedStateKeepsPreviousPlanDetails(): void
    {
        $callCount = 0;
        $service = $this->makeService([], function () use (&$callCount): ?string {
            $callCount++;
            return $callCount === 1
                ? json_encode(['valid' => true, 'status' => 'active', 'max_activations' => 3, 'active_activations' => 1])
                : null;
        });

        $service->activate('KEY-1');
        $service->refresh();

        $state = $service->state();
        $this->assertSame(3, $state['max_activations']);
        $this->assertSame(1, $state['active_activations']);
    }

    public function testActivateWithInvalidKeyKeepsFreePlan(): void
    {
        $service = $this->makeService([], fn(): string => json_encode(['valid' => false, 'error' => 'license_not_found']));

        $result = $service->activate('BAD-KEY');

        $this->assertFalse($result['valid']);
        $this->assertFalse($service->isPaidPlanActive());
    }

    public function testActivateWithoutConfiguredServerReturnsError(): void
    {
        $service = $this->makeService(['base_url' => '', 'api_key' => '']);

        $result = $service->activate('KEY-1');

        $this->assertFalse($result['valid']);
        $this->assertSame('server_not_configured', $result['error']);
    }

    // -------------------------------------------------------------------------
    // isStale() / refreshIfStale()
    // -------------------------------------------------------------------------

    public function testRefreshIfStaleIsNoopWithoutLicenseKey(): void
    {
        $service = $this->makeService([], function (): never {
            $this->fail('le transport ne doit pas être appelé sans clé de licence enregistrée');
        });

        $service->refreshIfStale();
        $this->addToAssertionCount(1);
    }

    public function testRefreshIfStaleCallsRefreshWhenNeverChecked(): void
    {
        $service = $this->makeService(['check_interval_hours' => 24], fn(): string => json_encode(['valid' => true]));
        $this->store['license_key'] = 'KEY-1';

        $service->refreshIfStale();

        $this->assertTrue($service->isPaidPlanActive());
    }

    public function testRefreshIfStaleSkipsRefreshWhenRecentlyChecked(): void
    {
        $service = $this->makeService(['check_interval_hours' => 24], function (): never {
            $this->fail('le transport ne doit pas être appelé si l\'état est encore frais');
        });
        $this->store['license_key'] = 'KEY-1';
        $this->store['license_state'] = json_encode(['status' => 'inactive', 'checked_at' => time()]);

        $service->refreshIfStale();
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // refresh() — grâce hors-ligne
    // -------------------------------------------------------------------------

    public function testRefreshStaysPaidWithinGracePeriodWhenServerUnreachable(): void
    {
        $callCount = 0;
        $service = $this->makeService([], function () use (&$callCount): ?string {
            $callCount++;
            return $callCount === 1 ? json_encode(['valid' => true, 'status' => 'active']) : null;
        });

        $service->activate('KEY-1');
        $this->assertTrue($service->isPaidPlanActive());

        $service->refresh();
        $this->assertTrue($service->isPaidPlanActive(), 'encore dans la période de grâce');
    }

    public function testIsPaidPlanActiveFalseAfterGracePeriodExpires(): void
    {
        $service = $this->makeService(['grace_period_days' => 14], fn(): string => json_encode(['valid' => true]));
        $service->activate('KEY-1');

        $state = json_decode($this->store['license_state'], true);
        $state['status'] = 'degraded';
        $state['last_valid_at'] = time() - (20 * 86400);
        $this->store['license_state'] = json_encode($state);

        $this->assertFalse($service->isPaidPlanActive());
    }

    public function testRefreshWithExplicitInvalidResponseFailsImmediatelyWithoutGrace(): void
    {
        $callCount = 0;
        $service = $this->makeService([], function () use (&$callCount): string {
            $callCount++;
            return $callCount === 1
                ? json_encode(['valid' => true])
                : json_encode(['valid' => false, 'error' => 'license_revoked']);
        });

        $service->activate('KEY-1');
        $this->assertTrue($service->isPaidPlanActive());

        $service->refresh();
        $this->assertFalse($service->isPaidPlanActive());
    }

    public function testRefreshIsNoopWithoutLicenseKey(): void
    {
        $service = $this->makeService([], function (): never {
            $this->fail('le transport ne doit pas être appelé sans clé de licence enregistrée');
        });

        $this->assertNull($service->refresh());
    }

    // -------------------------------------------------------------------------
    // deactivate()
    // -------------------------------------------------------------------------

    public function testDeactivateClearsLicenseAndReturnsToFreePlan(): void
    {
        $service = $this->makeService([], fn(): string => json_encode(['valid' => true]));
        $service->activate('KEY-1');
        $this->assertTrue($service->isPaidPlanActive());

        $service->deactivate();

        $this->assertNull($service->licenseKey());
        $this->assertFalse($service->isPaidPlanActive());
    }

    // -------------------------------------------------------------------------
    // entitlements() — decode le license_token signe, jamais les champs plats
    // -------------------------------------------------------------------------

    public function testEntitlementsReturnsVerifiedTokenPayload(): void
    {
        $tokenVerifier = new LicenseTokenVerifier(self::$keypair['public']);
        $token = $this->signToken(['limits' => ['max_stores' => 3, 'max_employees' => 50]]);

        $service = $this->makeService([], fn(): string => json_encode([
            'valid' => true, 'status' => 'active', 'license_token' => $token,
        ]), $tokenVerifier);
        $service->activate('KEY-1');

        $this->assertSame(['max_stores' => 3, 'max_employees' => 50], $service->entitlements()['limits']);
    }

    public function testEntitlementsNullWithoutAnyToken(): void
    {
        $service = $this->makeService([], fn(): string => json_encode(['valid' => true, 'status' => 'active']));
        $service->activate('KEY-1');

        $this->assertNull($service->entitlements());
    }

    /** Une base editee a la main avec un faux token (mauvaise signature) ne doit jamais etre prise en compte. */
    public function testEntitlementsNullWhenPublicKeyNotConfigured(): void
    {
        $token = $this->signToken(['limits' => ['max_stores' => 999]]);
        $service = $this->makeService([], fn(): string => json_encode([
            'valid' => true, 'status' => 'active', 'license_token' => $token,
        ]));
        $service->activate('KEY-1');

        $this->assertNull($service->entitlements());
    }

    public function testEntitlementsSurviveDegradedRefreshServerUnreachable(): void
    {
        $tokenVerifier = new LicenseTokenVerifier(self::$keypair['public']);
        $token = $this->signToken(['limits' => ['max_stores' => 3]]);

        $callCount = 0;
        $service = $this->makeService([], function () use (&$callCount, $token): ?string {
            $callCount++;
            return $callCount === 1
                ? json_encode(['valid' => true, 'status' => 'active', 'license_token' => $token])
                : null;
        }, $tokenVerifier);

        $service->activate('KEY-1');
        $service->refresh();

        $this->assertSame(['max_stores' => 3], $service->entitlements()['limits']);
    }
}
