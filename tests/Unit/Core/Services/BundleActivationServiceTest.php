<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\BundleDiscoveryService;
use kintai\Core\FeatureManager;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\BundleActivationService;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\PlanLimitService;
use PHPUnit\Framework\TestCase;

/**
 * Activation des bundles dans la limite du quota (plan gratuit : 4 bundles actifs) : activation automatique d'un
 * bundle installé s'il reste de la place, et règle « réduire toujours permis » au-dessus du quota.
 */
final class BundleActivationServiceTest extends TestCase
{
    private static string $bundlesDir;

    /** Cinq bundles déposés (noms propres à ce test : les classes sont globales au processus). */
    public static function setUpBeforeClass(): void
    {
        self::$bundlesDir = sys_get_temp_dir() . '/kintai-activation-' . uniqid();
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $dir = self::$bundlesDir . '/ActivationFake' . $letter;
            mkdir($dir, 0777, true);
            $file = $dir . '/ActivationFake' . $letter . 'Bundle.php';
            $slug = 'activation-' . strtolower($letter);
            file_put_contents($file, <<<PHP
            <?php
            declare(strict_types=1);
            namespace kintai\Bundles\ActivationFake{$letter};
            use kintai\Core\BundleContract\Bundle;
            final class ActivationFake{$letter}Bundle extends Bundle {
                public function getName(): string { return '{$slug}'; }
                public function register(): void {}
            }
            PHP);
            require $file;
        }
    }

    /** @param list<string> $enabled */
    private function service(array $enabled, ?AppSettingsRepositoryInterface $settings = null): BundleActivationService
    {
        return new BundleActivationService(
            $settings ?? $this->createStub(AppSettingsRepositoryInterface::class),
            new FeatureManager($enabled),
            new BundleDiscoveryService(self::$bundlesDir),
            new PlanLimitService(
                $this->createStub(StoreRepositoryInterface::class),
                $this->createStub(UserRepositoryInterface::class),
                new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
            ),
        );
    }

    public function testABundleIsActivatedWhenThereIsRoom(): void
    {
        $settings = $this->createMock(AppSettingsRepositoryInterface::class);
        $settings->expects($this->once())->method('set')->with('enabled_bundles', json_encode(['activation-a', 'activation-b']));

        $this->assertSame(BundleActivationService::ACTIVATED, $this->service(['activation-a'], $settings)->activateIfRoom('activation-b'));
    }

    public function testAFullQuotaLeavesTheBundleInactive(): void
    {
        $settings = $this->createMock(AppSettingsRepositoryInterface::class);
        $settings->expects($this->never())->method('set');

        $service = $this->service(['activation-a', 'activation-b', 'activation-c', 'activation-d'], $settings);

        $this->assertSame(BundleActivationService::QUOTA_FULL, $service->activateIfRoom('activation-e'));
        $this->assertSame(BundleActivationService::ALREADY, $service->activateIfRoom('activation-a'));
    }

    public function testAboveTheQuotaOnlyAdditionsAreRefused(): void
    {
        $five = ['a', 'b', 'c', 'd', 'e'];

        $this->assertTrue(BundleActivationService::allowsChange([], ['a', 'b', 'c', 'd'], 4), 'sous le quota');
        $this->assertFalse(BundleActivationService::allowsChange(['a', 'b', 'c', 'd'], $five, 4), 'ajout au-delà du quota');
        $this->assertTrue(BundleActivationService::allowsChange(array_merge($five, ['f', 'g']), $five, 4), 'réduction partielle, encore au-dessus');
        $this->assertTrue(BundleActivationService::allowsChange($five, $five, 4), 'rien ne change');
        $this->assertFalse(BundleActivationService::allowsChange($five, ['a', 'b', 'c', 'd', 'x'], 4), 'échange au-dessus du quota = ajout');
        $this->assertTrue(BundleActivationService::allowsChange([], $five, null), 'licence sans limite');
    }
}
