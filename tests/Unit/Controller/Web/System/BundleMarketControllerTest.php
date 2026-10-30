<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web\System;

use App\BundleDiscoveryService;
use App\InstalledBundleManifestStore;
use App\Models\AppSetting;
use App\Models\BundleRegistry;
use Tests\Support\InstalledBundleExpectations;
use Illuminate\Http\Request;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\BundleInstaller\BundleInstallerService;
use App\Services\BundleRegistry\BundleCatalogService;
use App\Services\BundleRegistry\BundleRegistryClient;
use App\Services\UpdateService;
use App\Http\Controllers\System\BundleMarketController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

final class BundleMarketControllerTest extends TestCase
{
    use InstalledBundleExpectations;
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
        kintai_test_registries([]);
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_settings([]);

        $this->bundlesDir = sys_get_temp_dir() . '/kintai-bundle-market-' . uniqid();
        mkdir($this->bundlesDir, 0777, true);

        $this->fakeCoreBasePath = sys_get_temp_dir() . '/kintai-fake-core-' . uniqid();
        mkdir($this->fakeCoreBasePath . '/config', 0777, true);
        file_put_contents($this->fakeCoreBasePath . '/config/app.php', "<?php\nreturn ['version' => '0.5.0'];\n");
    }

    protected function tearDown(): void
    {
        kintai_test_flash();
        $_GET = [];
        $_POST = [];
    }

    private function makeController(): BundleMarketController
    {
        $catalog = new BundleCatalogService(
            new BundleRegistryClient($this->registryFetcher),
        );

        $installer = new BundleInstallerService(
            new UpdateService($this->fakeCoreBasePath),
            new InstalledBundleManifestStore($this->bundlesDir . '/installed.json'),
            $this->bundlesDir,
            $this->releaseFetcher,
            $this->zipDownloader,
        );

        return new BundleMarketController(
            kintai_test_views(sys_get_temp_dir()),
            $catalog,
            $installer,
            new AuditLogger(),
            new BundleDiscoveryService(
                $this->bundlesDir . '/no-legacy-bundles-here',
                new InstalledBundleManifestStore($this->bundlesDir . '/installed.json'),
                $this->bundlesDir,
            ),
            new AppSettingsService(),
        );
    }

    public function testIndexRendersPage(): void
    {
        $response = $this->makeController()->index(Request::capture());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStoreRejectsInvalidUrl(): void
    {
        $_POST = ['name' => 'Mon registry', 'url' => 'not-a-url'];


        $response = $this->makeController()->store(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testStoreRejectsNonHttpsUrl(): void
    {
        $_POST = ['name' => 'Mon registry', 'url' => 'http://example.test/registry.json'];


        $response = $this->makeController()->store(Request::capture());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testStoreRejectsDuplicateUrl(): void
    {
        $_POST = ['name' => 'Mon registry', 'url' => 'https://example.test/registry.json'];
        BundleRegistry::create(['name' => 'Déjà là', 'url' => 'https://example.test/registry.json', 'is_official' => false]);


        $response = $this->makeController()->store(Request::capture());

        $this->assertStringContainsString('error=duplicate', $this->locationOf($response));
    }

    public function testStoreCreatesRegistryAndRedirectsToSuccess(): void
    {
        $_POST = ['name' => 'Mon registry', 'url' => 'https://example.test/registry.json'];

        $response = $this->makeController()->store(Request::capture());

        $this->assertStringContainsString('success=created', $this->locationOf($response));
        $this->assertSame(['Mon registry'], BundleRegistry::where('is_official', false)->pluck('name')->all());
    }

    public function testDestroyRefusesToDeleteTheOfficialRegistry(): void
    {
        $official = BundleRegistry::create(['name' => 'Officiel', 'url' => 'https://example.test/official.json', 'is_official' => true]);

        $req = Request::capture();
        $req->setRouteParams(['id' => (string) $official->id]);

        $response = $this->makeController()->destroy($req);

        $this->assertStringContainsString('error=delete_official_forbidden', $this->locationOf($response));
        $this->assertNotNull(BundleRegistry::find($official->id));
    }

    public function testDestroyDeletesANonOfficialRegistry(): void
    {
        $perso = BundleRegistry::create(['name' => 'Perso', 'url' => 'https://example.test/perso.json', 'is_official' => false]);

        $req = Request::capture();
        $req->setRouteParams(['id' => (string) $perso->id]);

        $response = $this->makeController()->destroy($req);

        $this->assertStringContainsString('success=deleted', $this->locationOf($response));
        $this->assertNull(BundleRegistry::find($perso->id));
    }

    public function testMarketRendersCatalogAggregatedFromRegistries(): void
    {
        kintai_test_registries([
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
        $this->seedInstalled('feedback', '1.0.0');

        $response = $this->makeController()->market(Request::capture());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Un registry schema 2 (versions par canal) ne doit jamais faire échouer la
     * résolution même quand le canal configuré (bundle_update_channel) diffère
     * du canal par défaut ('release') — cf. AppSettingsService::bundleUpdateChannel().
     */
    public function testMarketResolvesLatestVersionFromConfiguredChannelWithSchema2Registry(): void
    {
        kintai_test_settings(['bundle_update_channel' => 'alpha']);

        kintai_test_registries([
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
        $this->seedInstalled('daily-report', '1.0.0');

        $response = $this->makeController()->market(Request::capture());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSaveChannelPersistsChannelAndRedirects(): void
    {
        kintai_test_settings([]);
        $_POST = ['channel' => 'beta'];

        $response = $this->makeController()->saveChannel(Request::capture());

        $this->assertSame('beta', AppSetting::value('bundle_update_channel'));

        $this->assertStringContainsString('channel_saved=beta', $this->locationOf($response));
    }

    public function testSaveChannelRejectsUnknownValueAndFallsBackToRelease(): void
    {
        kintai_test_settings([]);
        $_POST = ['channel' => 'nightly'];

        $response = $this->makeController()->saveChannel(Request::capture());

        $this->assertSame('release', AppSetting::value('bundle_update_channel'));

        $this->assertStringContainsString('channel_saved=release', $this->locationOf($response));
    }

    public function testDryRunRejectsAnIncompleteRequest(): void
    {
        $_POST = ['slug' => '', 'repository_url' => '', 'version' => ''];

        $response = $this->makeController()->dryRun(Request::capture());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDryRunReturnsJsonResultFromInstaller(): void
    {
        $_POST = [
            'slug'            => 'fake-bundle',
            'repository_url'  => 'https://gitlab.com/someone/fake-bundle',
            'version'         => '1.0.0',
        ];

        $response = $this->makeController()->dryRun(Request::capture());

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('non reconnue', $data['error']);
    }

    public function testInstallRedirectsWithErrorWhenRequestIsIncomplete(): void
    {
        $_POST = ['slug' => 'feedback'];

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testInstallRejectsAThirdPartyBundleWithoutExplicitConfirmation(): void
    {
                $_POST = [
            'slug'            => 'unofficial-bundle',
            'repository_url'  => 'https://github.com/someone/unofficial-bundle',
            'version'         => '1.0.0',
            // confirm_third_party volontairement absent
        ];

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testInstallSucceedsForAThirdPartyBundleWithExplicitConfirmation(): void
    {
        $zipPath = $this->buildFixtureZip();
        $this->releaseFetcher = fn() => 'https://example.test/fake.zip';
        $this->zipDownloader = function (string $url, string $dest) use ($zipPath) { return copy($zipPath, $dest); };

        $_POST = [
            'slug'                => 'fake-bundle',
            'repository_url'      => 'https://github.com/AudricSan/kintai-bundle-fake',
            'version'             => '1.0.0',
            'confirm_third_party' => '1',
        ];

        $this->expectInstalledUpsert('fake-bundle', '1.0.0', null);

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('success=fake-bundle', $this->locationOf($response));
    }

    private const PIN_REGISTRY_URL = 'https://registry.example.test/registry.json';
    private const PINNED_COMMIT = '7f99e084014e8258003a8b2a8fd4d8a188cfa5c3';
    private const OTHER_COMMIT = '0123456789abcdef0123456789abcdef01234567';

    /** Configure un registry officiel qui épingle (ou non) le commit de fake-bundle 1.0.0, et l'archive servie. */
    private function arrangePinnedInstall(?string $pinnedCommit, ?string $servedCommit, bool $registryReachable = true): void
    {
        kintai_test_registries([
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
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::PINNED_COMMIT);
        $this->expectInstalledUpsert('fake-bundle');

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('success=fake-bundle', $this->locationOf($response));
    }

    public function testInstallIsRefusedWhenTheTagWasMovedAfterTheRegistryPinnedIt(): void
    {
        // Le registry épingle PINNED_COMMIT ; GitHub sert l'archive d'un autre commit (tag déplacé).
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT);
        $this->expectNoInstalledChange();

        $response = $this->makeController()->install(Request::capture());

        // L'URL ne porte qu'un code ; le détail (en session) est affiché par le layout, jamais lu dans l'URL.
        $location = $this->locationOf($response);
        $this->assertStringContainsString('error=failed', $location);
        $this->assertStringNotContainsString('refus', urldecode($location));
        $flash = kintai_test_flash();
        $this->assertStringContainsString('refus', $flash['danger'] ?? '');
    }

    public function testInstallIsRefusedWhenTheRegistryCannotBeReadToCheckThePin(): void
    {
        // Sinon, empêcher la lecture du registry suffirait à désactiver la vérification.
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT, registryReachable: false);
        $this->expectNoInstalledChange();

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('error=', $this->locationOf($response));
    }

    public function testInstallStillWorksWhenTheRegistryDoesNotPinThisVersion(): void
    {
        $this->arrangePinnedInstall(null, self::OTHER_COMMIT);
        $this->expectInstalledUpsert('fake-bundle');

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('success=fake-bundle', $this->locationOf($response));
    }

    public function testTheExpectedCommitComesFromTheRegistryNotFromTheRequest(): void
    {
        // Un formulaire ne doit pas pouvoir choisir ce qu'on vérifie : un champ « commit » forgé est ignoré.
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT);
        $_POST['commit'] = self::OTHER_COMMIT;
        $_POST['expected_commit'] = self::OTHER_COMMIT;
        $this->expectNoInstalledChange();

        $response = $this->makeController()->install(Request::capture());

        $this->assertStringContainsString('error=', $this->locationOf($response));
    }

    public function testDryRunAlsoChecksThePinnedCommit(): void
    {
        $this->arrangePinnedInstall(self::PINNED_COMMIT, self::OTHER_COMMIT);

        $response = $this->makeController()->dryRun(Request::capture());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('refusée', json_decode($response->getContent(), true)['error']);
    }

    public function testUninstallRedirectsWithErrorWhenSlugIsMissing(): void
    {
        $_POST = ['slug' => ''];

        $this->expectNoInstalledChange();

        $response = $this->makeController()->uninstall(Request::capture());

        $this->assertStringContainsString('error=invalid', $this->locationOf($response));
    }

    public function testUninstallFailsForABundleNotManagedByTheInstaller(): void
    {
        kintai_test_settings([]);
        $_POST = ['slug' => 'daily-report'];
        $this->expectNoInstalledChange();
        $response = $this->makeController()->uninstall(Request::capture());

        $this->assertStringNotContainsString('uninstalled=', $this->locationOf($response));
    }

    public function testUninstallRemovesTheBundleAndClearsItFromEnabledBundles(): void
    {
        kintai_test_settings([]);
        mkdir($this->bundlesDir . '/feedback/1.0.0', 0777, true);
        $_POST = ['slug' => 'feedback'];
        $this->seedInstalled('feedback', '1.0.0');
        $this->expectInstalledDelete('feedback');
        kintai_test_settings(['enabled_bundles' => json_encode(['feedback', 'messaging'])]);
        $response = $this->makeController()->uninstall(Request::capture());

        $this->assertSame(['messaging'], json_decode((string) AppSetting::value('enabled_bundles'), true));

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

    private function locationOf(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return kintai_test_headers($response)['Location'] ?? '';
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
