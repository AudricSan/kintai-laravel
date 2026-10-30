<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\AppSetting;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Services\LicenseClientService;
use App\Services\LicenseTokenVerifier;
use App\Services\PlanLimitService;
use Tests\Support\TestSigningKeypair;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class PlanLimitServiceTest extends TestCase
{
    /** @var array{private: string, public: string} Paire jetable, generee a la volee — voir TestSigningKeypair. */
    private static array $keypair;

    private StoreRepositoryInterface&Stub $stores;
    private UserRepositoryInterface&Stub $users;
    private PlanLimitService $service;

    public static function setUpBeforeClass(): void
    {
        self::$keypair = TestSigningKeypair::generate();
    }

    protected function setUp(): void
    {
        $this->stores  = $this->createStub(StoreRepositoryInterface::class);
        $this->users   = $this->createStub(UserRepositoryInterface::class);
        kintai_test_settings();
        $this->service = $this->makeService();
    }

    /** Aucune clé de licence enregistrée (table app_settings vide) : isPaidPlanActive() reste false, donc le plan gratuit s'applique. */
    private function makeService(): PlanLimitService
    {
        $license = new LicenseClientService(['base_url' => '', 'api_key' => '', 'grace_period_days' => 14]);

        return new PlanLimitService($this->stores, $this->users, $license);
    }

    public function testMaxActiveBundlesIsFourOnFreePlan(): void
    {
        $this->assertSame(4, $this->service->maxActiveBundles());
    }

    public function testMaxStoresAndEmployeesOnFreePlan(): void
    {
        $this->assertSame(1, $this->service->maxStores());
        $this->assertSame(15, $this->service->maxEmployees());
    }

    public function testCurrentCountsDelegateToRepositories(): void
    {
        $this->stores->method('countActive')->willReturn(1);
        $this->users->method('countActive')->willReturn(7);

        $this->assertSame(1, $this->service->currentStoreCount());
        $this->assertSame(7, $this->service->currentEmployeeCount());
    }

    public function testAssertCanCreateStoreAllowsWhenBelowLimit(): void
    {
        $this->stores->method('countActive')->willReturn(0);
        $this->service->assertCanCreateStore();
        $this->addToAssertionCount(1);
    }

    public function testAssertCanCreateStoreThrowsWhenLimitReached(): void
    {
        $this->stores->method('countActive')->willReturn(1);

        $this->expectException(PlanLimitExceededException::class);
        $this->service->assertCanCreateStore();
    }

    public function testAssertCanCreateEmployeeAllowsWhenBelowLimit(): void
    {
        $this->users->method('countActive')->willReturn(14);
        $this->service->assertCanCreateEmployee();
        $this->addToAssertionCount(1);
    }

    public function testAssertCanCreateEmployeeThrowsWhenLimitReached(): void
    {
        $this->users->method('countActive')->willReturn(15);

        $this->expectException(PlanLimitExceededException::class);
        $this->service->assertCanCreateEmployee();
    }

    // -------------------------------------------------------------------------
    // Plan payant (licence active) : toutes les limites sont levées
    // -------------------------------------------------------------------------

    private function makePaidService(): PlanLimitService
    {
        kintai_test_settings();

        $license = new LicenseClientService(
            ['base_url' => 'https://license.test/api/v1', 'api_key' => 'kintai-key', 'grace_period_days' => 14],
            transport: fn(): string => json_encode(['valid' => true, 'status' => 'active']),
        );
        $license->activate('KEY-1');

        return new PlanLimitService($this->stores, $this->users, $license);
    }

    public function testMaxActiveBundlesIsUnlimitedOnPaidPlan(): void
    {
        $this->assertNull($this->makePaidService()->maxActiveBundles());
    }

    public function testMaxStoresAndEmployeesAreUnlimitedOnPaidPlan(): void
    {
        $service = $this->makePaidService();

        $this->assertNull($service->maxStores());
        $this->assertNull($service->maxEmployees());
    }

    public function testAssertCanCreateStoreNeverThrowsOnPaidPlan(): void
    {
        $this->stores->method('countActive')->willReturn(99);
        $this->makePaidService()->assertCanCreateStore();
        $this->addToAssertionCount(1);
    }

    public function testAssertCanCreateEmployeeNeverThrowsOnPaidPlan(): void
    {
        $this->users->method('countActive')->willReturn(999);
        $this->makePaidService()->assertCanCreateEmployee();
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Palier avec limites specifiques (license_token verifie) — remplace le
    // "tout illimite" par defaut du plan payant par les chiffres propres a la
    // licence, ex: palier "Pro" a 10 magasins / 200 employes / bundles illimites.
    // -------------------------------------------------------------------------

    private function makeTieredService(array $limits): PlanLimitService
    {
        $privateKey = openssl_pkey_get_private(self::$keypair['private']);
        $encode = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $encodedPayload = $encode(json_encode(['limits' => $limits], JSON_THROW_ON_ERROR));
        openssl_sign($encodedPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $token = $encodedPayload . '.' . $encode($signature);

        kintai_test_settings();

        $license = new LicenseClientService(
            ['base_url' => 'https://license.test/api/v1', 'api_key' => 'kintai-key', 'grace_period_days' => 14],
            transport: fn(): string => json_encode(['valid' => true, 'status' => 'active', 'license_token' => $token]),
            tokenVerifier: new LicenseTokenVerifier(self::$keypair['public']),
        );
        $license->activate('KEY-1');

        return new PlanLimitService($this->stores, $this->users, $license);
    }

    public function testTierWithExplicitLimitsAppliesThemInsteadOfUnlimited(): void
    {
        $service = $this->makeTieredService(['max_stores' => 10, 'max_employees' => 200]);

        $this->assertSame(10, $service->maxStores());
        $this->assertSame(200, $service->maxEmployees());
    }

    public function testTierWithMissingKeyStaysUnlimitedForThatDimension(): void
    {
        // "Business" par ex. : magasins limites, mais aucune limite sur les bundles (cle absente).
        $service = $this->makeTieredService(['max_stores' => 50]);

        $this->assertNull($service->maxActiveBundles());
    }

    public function testTierWithExplicitNullStaysUnlimited(): void
    {
        $service = $this->makeTieredService(['max_bundles' => null]);

        $this->assertNull($service->maxActiveBundles());
    }

    public function testAssertCanCreateStoreThrowsWhenTierLimitReached(): void
    {
        $service = $this->makeTieredService(['max_stores' => 3]);
        $this->stores->method('countActive')->willReturn(3);

        $this->expectException(PlanLimitExceededException::class);
        $service->assertCanCreateStore();
    }
}
