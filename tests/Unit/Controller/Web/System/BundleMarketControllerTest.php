<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use kintai\Core\BundleDiscoveryService;
use kintai\Core\InstalledBundleManifestStore;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\BundleRegistryRepositoryInterface;
use kintai\Core\Repositories\InstalledBundleRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\BundleInstaller\BundleInstallerService;
use kintai\Core\Services\BundleRegistry\BundleCatalogService;
use kintai\Core\Services\BundleRegistry\BundleRegistryClient;
use kintai\Core\Services\UpdateService;
use kintai\UI\Controller\Web\System\BundleMarketController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

final class BundleMarketControllerTest extends TestCase
{
    private BundleRegistryRepositoryInterface&Stub $registries;
    private InstalledBundleRepositoryInterface&Stub $installedBundles;
    private AppSettingsRepositoryInterface&Stub $appSettings;
    private ?\Closure $registryFetcher = null;
    private ?\Closure $releaseFetcher = null;
    private ?\Closure $zipDownloader = null;
    private string $bundlesDir;
    private string $fakeCoreBasePath;

    protected function setUp(): void
    {
        $this->ensureViewFile('system.bundle-registries');
        $this->ensureViewFile('system.bundle-market');
        $this->ensureViewFile('layout.app');
        $this->registries = $this->createStub(BundleRegistryRepositoryInterface::class);
        $this->installedBundles = $this->createStub(InstalledBundleRepositoryInterface::class);
        $this->appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $this->appSettings->method('all')->willReturn([]);

        $this->bundlesDir = sys_get_temp_dir() . '/kintai-bundle-market-' . uniqid();
        mkdir($this->bundlesDir, 0777, true);

        $this->fakeCoreBasePath = sys_get_temp_dir() . '/kintai-fake-core-' . uniqid();
        mkdir($this->fakeCoreBasePath . '/config', 0777, true);
        file_put_contents($this->fakeCoreBasePath . '/config/app.php', "<?php\nreturn ['version' => '0.5.0'];\n");
    }

    protected function tearDown(): void
    {
        \kintai\Core\SessionFlash::pull();
        $_GET = [];
        $_POST = [];
    }

    private function makeController(): BundleMarketController
    {
        $catalog = new BundleCatalogService(
            $this->registries,
            new BundleRegistryClient($this->registryFetcher),
        );

        $installer = new BundleInstallerService(
            new UpdateService($this->fakeCoreBasePath),
            $this->installedBundles,
            new InstalledBundleManifestStore($this->bundlesDir . '/installed.json'),
            $this->bundlesDir,
            $this->releaseFetcher,
            $this->zipDownloader,
        );

        return new BundleMarketController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->registries,
            $catalog,
            $this->installedBundles,
            $installer,
            new AuditLogger(),
            $this->appSettings,
            new BundleDiscoveryService(
                $this->bundlesDir . '/no-legacy-bundles-here',
                new InstalledBundleManifestStore($this->bundlesDir . '/installed.json'),
                $this->bundlesDir,
            ),
            new AppSettingsService($this->appSettings),
        );
    }

    public function testIndexRendersPage(): void
    {
        $this->registries->method('all')->willReturn([]);

        $response = $this->makeController()->index(new Request());

        $this->assertSame(200, $response->status());
    }

    public function testStoreRejectsInvalidUrl(): void
    {
        $this->registries = $this->createMock(BundleRegistryRepositoryInterface::class);
        $_POST = ['name' => 'Mon registry', 'url' => 'not-a-url'];

        $this->registries->expects($this->never())->method('create');

        $response = $this->makeController()->store(new Request());

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testStoreRejectsNonHttpsUrl(): void
    {
        $this->registries = $this->createMock(BundleRegistryRepositoryInterface::class);
        $_POST = ['name' => 'Mon registry', 'url' => 'http://example.test/registry.json'];

        $this->registries->expects($this->never())->method('create');

        $response = $this->makeController()->store(new Request());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testStoreRejectsDuplicateUrl(): void
    {
        $this->registries = $this->createMock(BundleRegistryRepositoryInterface::class);
        $_POST = ['name' => 'Mon registry', 'url' => 'https://example.test/registry.json'];
        $this->registries->method('existsByUrl')->willReturn(true);

        $this->registries->expects($this->never())->method('create');

        $response = $this->makeController()->store(new Request());

        $this->assertStringContainsString('error=duplicate', $this->locationOf($response));
    }

    public function testStoreCreatesRegistryAndRedirectsToSuccess(): void
    {
        $this->registries = $this->createMock(BundleRegistryRepositoryInterface::class);
        $_POST = ['name' => 'Mon registry', 'url' => 'https://example.test/registry.json'];
        $this->registries->method('existsByUrl')->willReturn(false);
        $this->registries->expects($this->once())->method('create')
            ->with('Mon registry', 'https://example.test/registry.json')
            ->willReturn(['id' => 3, 'name' => 'Mon registry', 'url' => 'https://example.test/registry.json', 'is_official' => false]);

        $response = $this->makeController()->store(new Request());

        $this->assertStringContainsString('success=created', $this->locationOf($response));
    }

    public function testDestroyRefusesToDeleteTheOfficialRegistry(): void
    {
        $this->registries = $this->createMock(BundleRegistryRepositoryInterface::class);
        $this->registries->method('find')->willReturn(['id' => 1, 'name' => 'Officiel', 'url' => 'https://example.test/official.json', 'is_official' => true]);
        $this->registries->expects($this->never())->method('delete');

        $req = new Request();
        $req->setRouteParams(['id' => '1']);

        $response = $this->makeController()->destroy($req);

        $this->assertStringContainsString('error=delete_official_forbidden', $this->locationOf($response));
    }

    public function testDestroyDeletesANonOfficialRegistry(): void
    {
        $this->registries = $this->createMock(BundleRegistryRepositoryInterface::class);
        $this->registries->method('find')->willReturn(['id' => 2, 'name' => 'Perso', 'url' => 'https://example.test/perso.json', 'is_official' => false]);
        $this->registries->expects($this->once())->method('delete')->with(2);

        $req = new Request();
        $req->setRouteParams(['id' => '2']);

        $response = $this->makeController()->destroy($req);

        $this->assertStringContainsString('success=deleted', $this->locationOf($response));
    }

    public function testMarketRendersCatalogAggregatedFromRegistries(): void
    {
        $this->registries->method('all')->willReturn([
            ['id' => 1, 'name' => 'Registry officiel', 'url' => 'https://example.test/registry.json', 'is_official' => true],
        ]);
        $this->registryFetcher = fn(string $url) => json_encode([
            'schema_version' => 1,
            'name'           => 'Registry officiel',
            'bundles'        => [[
                'slug'            => 'feedback',
                'name'            => 'Retours utilisateurs',
                'description'     => '...',
                'repository_url'  => 'https://github.com/AudricSan/kintai-bundle-feedback',
                'versions'        => ['1.1.0', '1.0.0'],
            ]],
        ]);
        $this->installedBundles->method('all')->willReturn([
            ['slug' => 'feedback', 'active_version' => '1.0.0', 'source_registry_url' => null],
        ]);

        $response = $this->makeController()->market(new Request());

        $this->assertSame(200, $response->status());
    }

    /**
     * Un registry schema 2 (versions par canal) ne doit jamais faire échouer la
     * résolution même quand le canal configuré (bundle_update_channel) diffère
     * du canal par défaut ('release') — cf. AppSettingsService::bundleUpdateChannel().
     */
    public function testMarketResolvesLatestVersionFromConfiguredChannelWithSchema2Registry(): void
    {
        $this->appSettings->method('all')->willReturn(['bundle_update_channel' => 'alpha']);

        $this->registries->method('all')->willReturn([
            ['id' => 1, 'name' => 'Registry officiel', 'url' => 'https://example.test/registry.json', 'is_official' => true],
        ]);
        $this->registryFetcher = fn(string $url) => json_encode([
            'schema_version' => 2,
            'name'           => 'Registry officiel',
            'bundles'        => [[
                'slug'            => 'daily-report',
                'name'            => 'Rapports journaliers',
                'description'     => '...',
                'repository_url'  => 'https://github.com/AudricSan/kintai-bundle-daily-report',
                'versions'        => [
                    'release' => ['1.0.0'],
                    'beta'    => ['1.1.1', '1.0.0'],
                    'alpha'   => ['1.1.1', '1.0.0'],
                ],
            ]],
        ]);
        $this->installedBundles->method('all')->willReturn([
            ['slug' => 'daily-report', 'active_version' => '1.0.0', 'source_registry_url' => null],
        ]);

        $response = $this->makeController()->market(new Request());

        $this->assertSame(200, $response->status());
    }

    public function testSaveChannelPersistsChannelAndRedirects(): void
    {
        $this->appSettings = $this->createMock(AppSettingsRepositoryInterface::class);
        $this->appSettings->method('all')->willReturn([]);
        $_POST = ['channel' => 'beta'];

        $this->appSettings->expects($this->once())->method('setMany')->with(['bundle_update_channel' => 'beta']);

        $response = $this->makeController()->saveChannel(new Request());

        $this->assertStringContainsString('channel_saved=beta', $this->locationOf($response));
    }

    public function testSaveChannelRejectsUnknownValueAndFallsBackToRelease(): void
    {
        $this->appSettings = $this->createMock(AppSettingsRepositoryInterface::class);
        $this->appSettings->method('all')->willReturn([]);
        $_POST = ['channel' => 'nightly'];

        $this->appSettings->expects($this->once())->method('setMany')->with(['bundle_update_channel' => 'release']);

        $response = $this->makeController()->saveChannel(new Request());

        $this->assertStringContainsString('channel_saved=release', $this->locationOf($response));
    }

    public function testDryRunRejectsAnIncompleteRequest(): void
    {
        $_POST = ['slug' => '', 'repository_url' => '', 'version' => ''];

        $response = $this->makeController()->dryRun(new Request());

        $this->assertSame(400, $response->status());
    }

    public function testDryRunReturnsJsonResultFromInstaller(): void
    {
        $_POST = [
            'slug'            => 'fake-bundle',
            'repository_url'  => 'https://gitlab.com/someone/fake-bundle',
            'version'         => '1.0.0',
        ];

        $response = $this->makeController()->dryRun(new Request());

        $this->assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('non reconnue', $data['error']);
    }

    public function testInstallRedirectsWithErrorWhenRequestIsIncomplete(): void
    {
        $_POST = ['slug' => 'feedback'];

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testInstallRejectsAThirdPartyBundleWithoutExplicitConfirmation(): void
    {
        $this->registries->method('all')->willReturn([]);
        $_POST = [
            'slug'            => 'unofficial-bundle',
            'repository_url'  => 'https://github.com/someone/unofficial-bundle',
            'version'         => '1.0.0',
            // confirm_third_party volontairement absent
        ];

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testInstallSucceedsForAThirdPartyBundleWithExplicitConfirmation(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $zipPath = $this->buildFixtureZip();
        $this->releaseFetcher = fn() => 'https://example.test/fake.zip';
        $this->zipDownloader = function (string $url, string $dest) use ($zipPath) { return copy($zipPath, $dest); };

        $_POST = [
            'slug'                => 'fake-bundle',
            'repository_url'      => 'https://github.com/AudricSan/kintai-bundle-fake',
            'version'             => '1.0.0',
            'confirm_third_party' => '1',
        ];

        $this->installedBundles->expects($this->once())->method('upsert')->with('fake-bundle', '1.0.0', null);

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('success=fake-bundle', $this->locationOf($response));
    }

    private const PIN_REGISTRY_URL = 'https://registry.example.test/registry.json';
    private const PINNED_COMMIT = '7f99e084014e8258003a8b2a8fd4d8a188cfa5c3';
    private const OTHER_COMMIT = '0123456789abcdef0123456789abcdef01234567';

    /** Configure un registry officiel qui épingle (ou non) le commit de fake-bundle 1.0.0, et l'archive servie. */
    private function arrangePinnedInstall(?string $pinnedCommit, ?string $servedCommit, bool $registryReachable = true): void
    {
        $this->registries->method('all')->willReturn([
            ['id' => 1, 'name' => 'Registry', 'url' => self::PIN_REGISTRY_URL, 'is_official' => true],
        ]);
        $this->registryFetcher = $registryReachable
            ? fn(string $url) => json_encode([
                'schema_version' => 2,
                'name'           => 'Registry',
                'bundles'        => [[
                    'slug'           => 'fake-bundle',
                    'name'           => 'Fake',
                    'repository_url' => 'https://github.com/AudricSan/kintai-bundle-fake',
                    'versions'       => ['release' => ['1.0.0'], 'beta' => ['1.0.0'], 'alpha' => ['1.0.0']],
                    'commits'        => $pinnedCommit !== null ? ['1.0.0' => $pinnedCommit] : [],
                ]],
            ])
            : fn(string $url) => null;

        $zipPath = $this->buildFixtureZip($servedCommit);
        $this->releaseFetcher = fn() => 'https://example.test/fake.zip';
        $this->zipDownloader = function (string $url, string $dest) use ($zipPath) { return copy($zipPath, $dest); };

        $_POST = [
            'slug'                => 'fake-bundle',
            'repository_url'      => 'https://github.com/AudricSan/kintai-bundle-fake',
            'version'             => '1.0.0',
            'registry_url'        => self::PIN_REGISTRY_URL,
            'confirm_third_party' => '1',
        ];
    }

    public function testInstallSucceedsWhenTheArchiveMatchesTheCommitPinnedByTheRegistry(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::PINNED_COMMIT);
        $this->installedBundles->expects($this->once())->method('upsert');

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('success=fake-bundle', $this->locationOf($response));
    }

    public function testInstallIsRefusedWhenTheTagWasMovedAfterTheRegistryPinnedIt(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        // Le registry épingle PINNED_COMMIT ; GitHub sert l'archive d'un autre commit (tag déplacé).
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT);
        $this->installedBundles->expects($this->never())->method('upsert');

        $response = $this->makeController()->install(new Request());

        // L'URL ne porte qu'un code ; le détail (en session) est affiché par le layout, jamais lu dans l'URL.
        $location = $this->locationOf($response);
        $this->assertStringContainsString('error=failed', $location);
        $this->assertStringNotContainsString('refus', urldecode($location));
        $flash = \kintai\Core\SessionFlash::pull();
        $this->assertSame('danger', $flash[0]['type'] ?? null);
        $this->assertStringContainsString('refus', $flash[0]['text'] ?? '');
    }

    public function testInstallIsRefusedWhenTheRegistryCannotBeReadToCheckThePin(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        // Sinon, empêcher la lecture du registry suffirait à désactiver la vérification.
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT, registryReachable: false);
        $this->installedBundles->expects($this->never())->method('upsert');

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('error=', $this->locationOf($response));
    }

    public function testInstallStillWorksWhenTheRegistryDoesNotPinThisVersion(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->arrangePinnedInstall(null, self::OTHER_COMMIT);
        $this->installedBundles->expects($this->once())->method('upsert');

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('success=fake-bundle', $this->locationOf($response));
    }

    public function testTheExpectedCommitComesFromTheRegistryNotFromTheRequest(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        // Un formulaire ne doit pas pouvoir choisir ce qu'on vérifie : un champ « commit » forgé est ignoré.
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT);
        $_POST['commit'] = self::OTHER_COMMIT;
        $_POST['expected_commit'] = self::OTHER_COMMIT;
        $this->installedBundles->expects($this->never())->method('upsert');

        $response = $this->makeController()->install(new Request());

        $this->assertStringContainsString('error=', $this->locationOf($response));
    }

    public function testDryRunAlsoChecksThePinnedCommit(): void
    {
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT);

        $response = $this->makeController()->dryRun(new Request());

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('refusée', json_decode($response->body(), true)['error']);
    }

    public function testUninstallRedirectsWithErrorWhenSlugIsMissing(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $_POST = ['slug' => ''];

        $this->installedBundles->expects($this->never())->method('delete');

        $response = $this->makeController()->uninstall(new Request());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testUninstallFailsForABundleNotManagedByTheInstaller(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->appSettings = $this->createMock(AppSettingsRepositoryInterface::class);
        $this->appSettings->method('all')->willReturn([]);
        $_POST = ['slug' => 'daily-report'];
        $this->installedBundles->method('find')->willReturn(null);
        $this->installedBundles->expects($this->never())->method('delete');
        $this->appSettings->expects($this->never())->method('set');

        $response = $this->makeController()->uninstall(new Request());

        $this->assertStringNotContainsString('uninstalled=', $this->locationOf($response));
    }

    public function testUninstallRemovesTheBundleAndClearsItFromEnabledBundles(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->appSettings = $this->createMock(AppSettingsRepositoryInterface::class);
        $this->appSettings->method('all')->willReturn([]);
        mkdir($this->bundlesDir . '/feedback/1.0.0', 0777, true);
        $_POST = ['slug' => 'feedback'];
        $this->installedBundles->method('find')->willReturn(['slug' => 'feedback', 'active_version' => '1.0.0', 'source_registry_url' => null]);
        $this->installedBundles->expects($this->once())->method('delete')->with('feedback');
        $this->appSettings->expects($this->atLeastOnce())->method('get')->with('enabled_bundles')->willReturn(json_encode(['feedback', 'messaging']));
        $this->appSettings->expects($this->once())->method('set')
            ->with('enabled_bundles', $this->callback(fn(string $json) => json_decode($json, true) === ['messaging']));

        $response = $this->makeController()->uninstall(new Request());

        $this->assertStringContainsString('uninstalled=feedback', $this->locationOf($response));
        $this->assertDirectoryDoesNotExist($this->bundlesDir . '/feedback');
    }

    /**
     * Construit un zip qui imite un zipball GitHub : un unique dossier racine
     * contenant bundle.json + src/.
     */
    private function buildFixtureZip(?string $commit = null): string
    {
        $manifest = [
            'slug'        => 'fake-bundle',
            'name'        => 'Fake Bundle',
            'version'     => '1.0.0',
            'namespace'   => 'kintai\\Bundles\\Installed\\FakeBundle',
            'entry_class' => 'kintai\\Bundles\\Installed\\FakeBundle\\FakeBundleBundle',
            'kintai_contract' => '^1.0',
            'kintai_core' => ['min' => '0.1.0', 'max' => '0.9.0'],
        ];

        $zipPath = sys_get_temp_dir() . '/kintai-market-fixture-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $root = 'AudricSan-kintai-bundle-fake-abc1234';
        $zip->addFromString("{$root}/bundle.json", json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->addFromString(
            "{$root}/src/FakeBundleBundle.php",
            "<?php\nnamespace kintai\\Bundles\\Installed\\FakeBundle;\nfinal class FakeBundleBundle {}\n",
        );
        if ($commit !== null) {
            $zip->setArchiveComment($commit);
        }
        $zip->close();

        return $zipPath;
    }

    private function locationOf(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');
        $ref->setAccessible(true);
        return $ref->getValue($response)['Location'] ?? '';
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
