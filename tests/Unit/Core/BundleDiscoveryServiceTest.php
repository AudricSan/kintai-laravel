<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleDiscoveryService;
use kintai\Core\InstalledBundleManifestStore;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

final class BundleDiscoveryServiceTest extends TestCase
{
    private string $emptyInstalledDir;
    private InstalledBundleManifestStore $emptyInstalledStore;

    protected function setUp(): void
    {
        // Isole les tests sur un dossier synthétique de leur propre installed.json,
        // sans effet de bord si un bundle est réellement installé sur cette machine
        // (storage/bundles/installed.json), et réciproquement.
        $this->emptyInstalledDir = sys_get_temp_dir() . '/kintai-installed-empty-' . uniqid();
        $this->emptyInstalledStore = new InstalledBundleManifestStore($this->emptyInstalledDir . '/installed.json');
    }

    public function testDiscoversALegacyBundleFromTheGivenDirectory(): void
    {
        // Depuis que TeamDirectory (le dernier bundle du monorepo) a été extrait vers
        // son propre dépôt, plus aucun bundle legacy n'est réellement présent dans
        // app/Bundles/ : ce test vérifie le mécanisme via un bundle synthétique plutôt
        // que contre le vrai dossier du dépôt. La découverte legacy reste utile pour un
        // bundle tiers déposé manuellement dans app/Bundles/ (voir BundleDiscoveryService).
        $dir = sys_get_temp_dir() . '/kintai-bundle-discovery-' . uniqid();
        $this->writeFakeLegacyBundle($dir, 'FakeLegacyBundle', 'fake-legacy-bundle');

        $service = new BundleDiscoveryService($dir, $this->emptyInstalledStore, $this->emptyInstalledDir);

        $discovered = $service->discover();

        $this->assertArrayHasKey('fake-legacy-bundle', $discovered);
        $this->assertNotSame('', $discovered['fake-legacy-bundle']['label']);
        $this->assertTrue(is_subclass_of($discovered['fake-legacy-bundle']['class'], Bundle::class));
        $this->assertSame('0.0.0', $discovered['fake-legacy-bundle']['version']);
    }

    public function testIgnoresDirectoriesWithNoMatchingBundleClass(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-bundle-discovery-' . uniqid();
        mkdir($dir . '/NotABundle', 0777, true);
        // Pas de fichier NotABundleBundle.php dedans -> doit être ignoré silencieusement.
        file_put_contents($dir . '/a-stray-file.txt', 'noop');

        $service = new BundleDiscoveryService($dir, $this->emptyInstalledStore, $this->emptyInstalledDir);

        $this->assertSame([], $service->discover());

        unlink($dir . '/a-stray-file.txt');
        rmdir($dir . '/NotABundle');
        rmdir($dir);
    }

    public function testReturnsEmptyArrayWhenBundlesDirDoesNotExist(): void
    {
        $service = new BundleDiscoveryService(
            sys_get_temp_dir() . '/kintai-does-not-exist-' . uniqid(),
            $this->emptyInstalledStore,
            $this->emptyInstalledDir,
        );

        $this->assertSame([], $service->discover());
    }

    public function testDiscoversAnInstalledBundleFromItsManifest(): void
    {
        $installedDir = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        $this->writeFakeInstalledBundle($installedDir, 'fake-bundle', '2.1.0');

        $store = new InstalledBundleManifestStore($installedDir . '/installed.json');
        $store->setActiveVersion('fake-bundle', '2.1.0');

        $service = new BundleDiscoveryService(
            sys_get_temp_dir() . '/kintai-does-not-exist-' . uniqid(),
            $store,
            $installedDir,
        );

        $discovered = $service->discover();

        $this->assertArrayHasKey('fake-bundle', $discovered);
        $this->assertSame('2.1.0', $discovered['fake-bundle']['version']);
        $this->assertSame('Fake Installed Bundle', $discovered['fake-bundle']['label']);
    }

    public function testInstalledBundleReportsItsActiveVersionNotTheManifestBaseline(): void
    {
        $installedDir = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        // Le workflow de release d'un bundle lit bundle.json comme ligne de base
        // "X.Y.0" et calcule Z tout seul : le manifeste d'une release 1.1.8
        // déclare donc toujours "1.1.0". La version affichée doit être celle du
        // dossier actif, pas celle du manifeste.
        $this->writeFakeInstalledBundle($installedDir, 'fake-baseline-bundle', '1.1.8', '1.1.0');

        $store = new InstalledBundleManifestStore($installedDir . '/installed.json');
        $store->setActiveVersion('fake-baseline-bundle', '1.1.8');

        $service = new BundleDiscoveryService(
            sys_get_temp_dir() . '/kintai-does-not-exist-' . uniqid(),
            $store,
            $installedDir,
        );

        $discovered = $service->discover();

        $this->assertSame('1.1.8', $discovered['fake-baseline-bundle']['version']);
    }

    /** Un bundle supprimé du projet (feedback) encore installé sur une instance ne doit plus être chargé. */
    public function testRetiredBundleStillInstalledIsNotDiscovered(): void
    {
        $installedDir = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        $this->writeFakeInstalledBundle($installedDir, 'feedback', '1.1.0');

        $store = new InstalledBundleManifestStore($installedDir . '/installed.json');
        $store->setActiveVersion('feedback', '1.1.0');

        $service = new BundleDiscoveryService(
            sys_get_temp_dir() . '/kintai-does-not-exist-' . uniqid(),
            $store,
            $installedDir,
        );

        $this->assertArrayNotHasKey('feedback', $service->discover());
    }

    public function testLegacySlugWinsOverAnInstalledBundleWithTheSameSlug(): void
    {
        $legacyDir = sys_get_temp_dir() . '/kintai-bundle-discovery-' . uniqid();
        $this->writeFakeLegacyBundle($legacyDir, 'FakeCollisionBundle', 'fake-collision-bundle');

        $installedDir = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        // Une collision entre un slug legacy et un slug installé dynamiquement ne
        // doit jamais faire gagner la version installée.
        $this->writeFakeInstalledBundle($installedDir, 'fake-collision-bundle', '9.9.9');

        $store = new InstalledBundleManifestStore($installedDir . '/installed.json');
        $store->setActiveVersion('fake-collision-bundle', '9.9.9');

        $service = new BundleDiscoveryService($legacyDir, $store, $installedDir);

        $discovered = $service->discover();

        $this->assertArrayHasKey('fake-collision-bundle', $discovered);
        $this->assertNotSame('9.9.9', $discovered['fake-collision-bundle']['version']);
    }

    private function writeFakeInstalledBundle(string $installedDir, string $slug, string $version, ?string $manifestVersion = null, array $extraManifest = []): void
    {
        $className = 'FakeInstalledBundle_' . str_replace('-', '_', $slug) . '_' . str_replace('.', '_', $version);
        $namespace = 'kintai\\Bundles\\Installed\\' . $className;
        $bundleRoot = $installedDir . '/' . $slug . '/' . $version;
        mkdir($bundleRoot . '/src', 0777, true);

        file_put_contents($bundleRoot . '/bundle.json', json_encode($extraManifest + [
            'slug'        => $slug,
            'name'        => 'Fake Installed Bundle',
            'version'     => $manifestVersion ?? $version,
            'namespace'   => $namespace,
            'entry_class' => $namespace . '\\' . $className . 'Bundle',
        ]));

        file_put_contents($bundleRoot . '/src/' . $className . 'Bundle.php', <<<PHP
        <?php
        declare(strict_types=1);
        namespace {$namespace};
        use kintai\Core\BundleContract\Bundle;
        final class {$className}Bundle extends Bundle {
            public function getName(): string { return '{$slug}'; }
            public function getLabel(): string { return 'Fake Installed Bundle'; }
            public function register(): void {}
        }
        PHP);

        require $bundleRoot . '/src/' . $className . 'Bundle.php';
    }

    /** @param array<string, mixed>|null $manifest bundle.json à côté de la classe (null : aucun) */
    private function writeFakeLegacyBundle(string $legacyDir, string $dirName, string $slug, ?array $manifest = null): void
    {
        $namespace = 'kintai\\Bundles\\' . $dirName;
        $bundleRoot = $legacyDir . '/' . $dirName;
        mkdir($bundleRoot, 0777, true);
        if ($manifest !== null) {
            file_put_contents($bundleRoot . '/bundle.json', json_encode($manifest + [
                'slug' => $slug, 'name' => 'Fake Legacy Bundle', 'version' => '1.0.0', 'namespace' => $namespace,
                'entry_class' => $namespace . '\\' . $dirName . 'Bundle',
            ]));
        }

        file_put_contents($bundleRoot . '/' . $dirName . 'Bundle.php', <<<PHP
        <?php
        declare(strict_types=1);
        namespace {$namespace};
        use kintai\Core\BundleContract\Bundle;
        final class {$dirName}Bundle extends Bundle {
            public function getName(): string { return '{$slug}'; }
            public function getLabel(): string { return 'Fake Legacy Bundle'; }
            public function register(): void {}
        }
        PHP);

        require $bundleRoot . '/' . $dirName . 'Bundle.php';
    }

    // -------------------------------------------------------------------------
    // Contrat de bundle versionné (kintai_contract)
    // -------------------------------------------------------------------------

    private function discoverOne(string $slug, string $version, array $manifest, string $coreVersion = '1.0.0'): array
    {
        $installedDir = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        $this->writeFakeInstalledBundle($installedDir, $slug, $version, null, $manifest);
        $store = new InstalledBundleManifestStore($installedDir . '/installed.json');
        $store->setActiveVersion($slug, $version);

        $service = new BundleDiscoveryService(sys_get_temp_dir() . '/kintai-does-not-exist-' . uniqid(), $store, $installedDir, $coreVersion);
        return $service->discover()[$slug];
    }

    public function testABundleRequiringTheProvidedContractIsCompatible(): void
    {
        $meta = $this->discoverOne('contract-ok', '1.0.0', ['kintai_contract' => '^1.0']);

        $this->assertNull($meta['incompatible']);
    }

    /** Un bundle devenu incompatible reste listé (l'Owner voit pourquoi) mais porte la raison : il ne sera pas chargé. */
    public function testABundleRequiringAFutureContractIsFlaggedIncompatible(): void
    {
        $meta = $this->discoverOne('contract-future', '1.0.0', ['kintai_contract' => '^99.0']);

        $this->assertNotNull($meta['incompatible']);
    }

    /** Sans kintai_contract (bundles publiés avant le contrat versionné) : listé mais jamais chargé, même avec une plage kintai_core ouverte. */
    public function testWithoutAContractTheBundleIsNotLoaded(): void
    {
        $this->assertSame('bundle_contract_missing', $this->discoverOne('legacy-ok', '1.0.0', ['kintai_core' => ['min' => '0.1.0', 'max' => '999.999.999']], '0.5.0')['incompatible']);
    }

    /** Bundle déposé dans app/Bundles/ : même règle qu'un bundle installé, son bundle.json doit déclarer le contrat. */
    public function testALegacyDropInBundleNeedsAContract(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-legacy-contract-' . uniqid();
        $this->writeFakeLegacyBundle($dir, 'FakeNoManifestBundle', 'fake-no-manifest');
        $this->writeFakeLegacyBundle($dir, 'FakeContractBundle', 'fake-contract', ['kintai_contract' => '^1.0']);
        $empty = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        mkdir($empty, 0777, true);

        $found = (new BundleDiscoveryService($dir, new InstalledBundleManifestStore($empty . '/installed.json'), $empty, '1.0.0'))->discover();

        $this->assertSame('bundle_contract_missing', $found['fake-no-manifest']['incompatible']);
        $this->assertNull($found['fake-contract']['incompatible']);
    }

    /** Avertissement de /admin/update : bundles installés qu'un Core fournissant ce contrat ne chargerait pas. */
    public function testInstalledIncompatibleWithATargetContract(): void
    {
        $installedDir = sys_get_temp_dir() . '/kintai-installed-' . uniqid();
        $this->writeFakeInstalledBundle($installedDir, 'still-ok', '1.0.0', null, ['kintai_contract' => '^1.5']);
        $this->writeFakeInstalledBundle($installedDir, 'needs-v2', '1.0.0', null, ['kintai_contract' => '^2.0']);
        $this->writeFakeInstalledBundle($installedDir, 'no-contract', '1.0.0');
        $store = new InstalledBundleManifestStore($installedDir . '/installed.json');
        foreach (['still-ok', 'needs-v2', 'no-contract'] as $slug) {
            $store->setActiveVersion($slug, '1.0.0');
        }
        $service = new BundleDiscoveryService(sys_get_temp_dir() . '/kintai-does-not-exist-' . uniqid(), $store, $installedDir, '1.0.0');

        $incompatible = $service->installedIncompatibleWith('1.6.0');

        $this->assertSame(['needs-v2', 'no-contract'], array_column($incompatible, 'slug'));
        $this->assertSame('bundle_contract_missing', $incompatible[1]['reason']);
        $this->assertSame(['still-ok', 'no-contract'], array_column($service->installedIncompatibleWith('2.1.0'), 'slug'));
    }
}
