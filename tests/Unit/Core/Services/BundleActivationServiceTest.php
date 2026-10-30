<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Services;

use App\BundleDiscoveryService;
use App\FeatureManager;
use App\Models\AppSetting;
use App\Models\Store;
use App\Repositories\UserRepositoryInterface;
use App\Services\BundleActivationService;
use App\Services\LicenseClientService;
use App\Services\PlanLimitService;
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
            use App\Bundles\Bundle;
            final class ActivationFake{$letter}Bundle extends Bundle {
                public function getName(): string { return '{$slug}'; }
                public function register(): void {}
            }
            PHP);
            require $file;
        }
    }

    /** @param list<string> $enabled */
    private function service(array $enabled): BundleActivationService
    {
        kintai_test_settings();

        return new BundleActivationService(
            new FeatureManager($enabled),
            new BundleDiscoveryService(self::$bundlesDir),
            new PlanLimitService(
                $this->createStub(UserRepositoryInterface::class),
                new LicenseClientService(['base_url' => '', 'api_key' => '']),
            ),
        );
    }

    public function testABundleIsActivatedWhenThereIsRoom(): void
    {
        $this->assertSame(BundleActivationService::ACTIVATED, $this->service(['activation-a'])->activateIfRoom('activation-b'));
        $this->assertSame(['activation-a', 'activation-b'], json_decode((string) AppSetting::value('enabled_bundles'), true));
    }

    public function testAFullQuotaLeavesTheBundleInactive(): void
    {
        $service = $this->service(['activation-a', 'activation-b', 'activation-c', 'activation-d']);

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
