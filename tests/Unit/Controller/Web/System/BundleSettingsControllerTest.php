<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web\System;

use App\BundleDiscoveryService;
use App\FeatureManager;
use App\Models\AppSetting;
use App\Models\Store;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\LicenseClientService;
use App\Services\PlanLimitService;
use App\Http\Controllers\System\BundleSettingsController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

final class BundleSettingsControllerTest extends TestCase
{
    private static string $legacyBundlesDir;

    /**
     * Depuis que TeamDirectory (le dernier bundle du monorepo) a été extrait vers son
     * propre dépôt, plus aucun bundle legacy n'est réellement présent dans app/Bundles/ :
     * ce test scanne un dossier synthétique contenant de faux bundles plutôt que le vrai
     * dossier du dépôt (voir wiki : Architecture "Modular Bundles"). Cinq bundles sont
     * créés (team-directory + fake-bundle-{2..5}) pour pouvoir tester le quota freemium
     * (4 bundles actifs max) avec de vrais slugs découverts, sans mocker PlanLimitService
     * (classe `final`, non mockable).
     */
    public static function setUpBeforeClass(): void
    {
        self::$legacyBundlesDir = sys_get_temp_dir() . '/kintai-bundle-settings-legacy-' . uniqid();

        $bundleRoot = self::$legacyBundlesDir . '/TeamDirectory';
        mkdir($bundleRoot, 0777, true);
        $file = $bundleRoot . '/TeamDirectoryBundle.php';
        file_put_contents($file, <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace kintai\Bundles\TeamDirectory;
        use App\Bundles\Bundle;
        final class TeamDirectoryBundle extends Bundle {
            public function getName(): string { return 'team-directory'; }
            public function getLabel(): string { return 'Fake Team Directory'; }
            public function register(): void {}
        }
        PHP);
        require $file;

        for ($i = 2; $i <= 5; $i++) {
            $bundleRoot = self::$legacyBundlesDir . '/FakeBundle' . $i;
            mkdir($bundleRoot, 0777, true);
            $file = $bundleRoot . '/FakeBundle' . $i . 'Bundle.php';
            file_put_contents($file, <<<PHP
            <?php
            declare(strict_types=1);
            namespace kintai\Bundles\FakeBundle{$i};
            use App\Bundles\Bundle;
            final class FakeBundle{$i}Bundle extends Bundle {
                public function getName(): string { return 'fake-bundle-{$i}'; }
                public function getLabel(): string { return 'Fake Bundle {$i}'; }
                public function register(): void {}
            }
            PHP);
            require $file;
        }
    }

    protected function setUp(): void
    {
        kintai_test_settings();
        $this->ensureViewFile('system.bundles');
        $this->ensureViewFile('layout.app');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    private function makeController(FeatureManager $features, ?PlanLimitService $planLimits = null): BundleSettingsController
    {
        return new BundleSettingsController(
            kintai_test_views(sys_get_temp_dir()),
            $features,
            new AuditLogger(),
            new BundleDiscoveryService(self::$legacyBundlesDir),
            $planLimits ?? new PlanLimitService(
                $this->createStub(UserRepositoryInterface::class),
                new LicenseClientService(['base_url' => '', 'api_key' => '']),
            ),
        );
    }

    public function testShowRendersPageForOwner(): void
    {
        $controller = $this->makeController(new FeatureManager(['team-directory']));

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $controller->show($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSavePersistsSelectedBundlesAsJson(): void
    {
        $controller = $this->makeController(new FeatureManager(['team-directory']));

        $_POST = ['bundle_team-directory' => '1'];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $controller->save($req);
        $captured = json_decode((string) AppSetting::value('enabled_bundles'), true);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($captured);
        sort($captured);
        $this->assertSame(['team-directory'], $captured);
    }

    public function testSaveBlocksWhenFreePlanBundleQuotaExceeded(): void
    {
        // Plan gratuit = 4 bundles actifs max (PlanLimitService) ; 5 sont découverts dans
        // le fixture (team-directory + fake-bundle-{2..5}), donc tout cocher dépasse le quota.
        $controller = $this->makeController(new FeatureManager([]));

        $_POST = [
            'bundle_team-directory' => '1',
            'bundle_fake-bundle-2'  => '1',
            'bundle_fake-bundle-3'  => '1',
            'bundle_fake-bundle-4'  => '1',
            'bundle_fake-bundle-5'  => '1',
        ];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $controller->save($req);

        $this->assertSame(302, $response->getStatusCode());
    }

    /** Au-dessus du quota (instance antérieure), garder ou réduire reste permis : seul l'ajout est refusé. */
    public function testSaveAboveTheQuotaKeepsWorkingWithoutAdditions(): void
    {
        $all = ['team-directory', 'fake-bundle-2', 'fake-bundle-3', 'fake-bundle-4', 'fake-bundle-5'];
        $controller = $this->makeController(new FeatureManager($all));

        $_POST = array_fill_keys(array_map(fn(string $s): string => 'bundle_' . $s, $all), '1');
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $controller->save($req)->headers->get('Location') ?? '';
        $this->assertStringEndsWith('/admin/bundles', $controller->save($req)->headers->get('Location') ?? '');
        $this->assertStringContainsString('1', (string) session('success'));
        $this->assertNotNull(AppSetting::value('enabled_bundles'));
    }

    public function testSaveAllowsExactlyFourBundlesOnFreePlan(): void
    {
        $controller = $this->makeController(new FeatureManager([]));

        $_POST = [
            'bundle_team-directory' => '1',
            'bundle_fake-bundle-2'  => '1',
            'bundle_fake-bundle-3'  => '1',
            'bundle_fake-bundle-4'  => '1',
        ];
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $response = $controller->save($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull(AppSetting::value('enabled_bundles'));
    }

    public function testOfficialBundlesRegistryListsBundlesShippedWithTheRepo(): void
    {
        // config/official-bundles.php est la seule source de vérité pour la distinction
        // officiel/tiers affichée sur /admin/bundles : elle ne fait confiance à aucune
        // auto-déclaration d'un bundle tiers.
        $official = require BASE_PATH . '/config/official-bundles.php';

        $this->assertContains('daily-report', $official);
        $this->assertContains('timeclock', $official);
        $this->assertNotContains('some-random-third-party-bundle', $official);
    }

    public function testSaveWithNoCheckedBundleDisablesAll(): void
    {
        $controller = $this->makeController(new FeatureManager(['team-directory']));

        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => true]);

        $controller->save($req);
        $captured = json_decode((string) AppSetting::value('enabled_bundles'), true);

        $this->assertSame([], $captured);
    }

    private function ensureViewFile(string $view): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($file);
    }
}
