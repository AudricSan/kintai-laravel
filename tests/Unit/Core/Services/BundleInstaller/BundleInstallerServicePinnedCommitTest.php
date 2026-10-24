<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services\BundleInstaller;

use kintai\Core\InstalledBundleManifestStore;
use kintai\Core\Repositories\InstalledBundleRepositoryInterface;
use kintai\Core\Services\BundleInstaller\BundleInstallerService;
use kintai\Core\Services\UpdateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 5));
}

/**
 * Le registry épingle le commit de chaque version ; l'installateur compare l'archive téléchargée à ce
 * commit AVANT de l'extraire. Un tag déplacé vers un autre commit (dépôt compromis) est refusé sans
 * rien laisser sur le disque.
 */
final class BundleInstallerServicePinnedCommitTest extends TestCase
{
    private const PINNED = '7f99e084014e8258003a8b2a8fd4d8a188cfa5c3';
    private const OTHER  = '0123456789abcdef0123456789abcdef01234567';
    private const REPO   = 'https://github.com/AudricSan/kintai-bundle-fake';

    private string $bundlesDir;
    private string $fakeCoreBasePath;
    private InstalledBundleRepositoryInterface&Stub $installedBundles;

    protected function setUp(): void
    {
        $this->bundlesDir = sys_get_temp_dir() . '/kintai-pinned-' . uniqid();
        mkdir($this->bundlesDir, 0777, true);

        $this->fakeCoreBasePath = sys_get_temp_dir() . '/kintai-fake-core-' . uniqid();
        mkdir($this->fakeCoreBasePath . '/config', 0777, true);
        file_put_contents($this->fakeCoreBasePath . '/config/app.php', "<?php\nreturn ['version' => '0.5.0'];\n");

        $this->installedBundles = $this->createStub(InstalledBundleRepositoryInterface::class);
    }

    /** Un zipball GitHub valide portant `$commit` dans son commentaire et son dossier racine. */
    private function githubZip(string $commit): string
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
        $path = sys_get_temp_dir() . '/kintai-pinned-fixture-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $root = 'AudricSan-kintai-bundle-fake-' . substr($commit, 0, 7);
        $zip->addFromString("{$root}/bundle.json", json_encode($manifest));
        $zip->addFromString(
            "{$root}/src/FakeBundleBundle.php",
            "<?php\nnamespace kintai\\Bundles\\Installed\\FakeBundle;\nfinal class FakeBundleBundle {}\n",
        );
        $zip->setArchiveComment($commit);
        $zip->close();

        return $path;
    }

    private function service(string $servedZip): BundleInstallerService
    {
        return new BundleInstallerService(
            new UpdateService($this->fakeCoreBasePath),
            $this->installedBundles,
            new InstalledBundleManifestStore($this->bundlesDir . '/installed.json'),
            $this->bundlesDir,
            fn() => 'https://example.test/fake.zip',
            static fn(string $url, string $dest): bool => copy($servedZip, $dest),
        );
    }

    public function testInstallSucceedsWhenTheArchiveMatchesThePinnedCommit(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->installedBundles->expects($this->once())->method('upsert');

        $result = $this->service($this->githubZip(self::PINNED))
            ->install('fake-bundle', self::REPO, '1.0.0', null, null, self::PINNED);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertDirectoryExists($this->bundlesDir . '/fake-bundle/1.0.0');
    }

    public function testInstallIsRefusedWhenTheTagWasMovedToAnotherCommit(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        // GitHub sert l'archive du commit ACTUEL du tag ; le registry en a épinglé un autre.
        $this->installedBundles->expects($this->never())->method('upsert');

        $result = $this->service($this->githubZip(self::OTHER))
            ->install('fake-bundle', self::REPO, '1.0.0', null, null, self::PINNED);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('installation refusée', (string) $result->error);
    }

    public function testARefusedInstallLeavesNothingOnDisk(): void
    {
        $this->service($this->githubZip(self::OTHER))
            ->install('fake-bundle', self::REPO, '1.0.0', null, null, self::PINNED);

        $this->assertDirectoryDoesNotExist($this->bundlesDir . '/fake-bundle/1.0.0', 'Rien ne doit être installé.');
        $this->assertSame([], glob($this->bundlesDir . '/fake-bundle/.staging/*.zip') ?: [], "L'archive refusée doit être supprimée.");
        $this->assertDirectoryDoesNotExist($this->bundlesDir . '/fake-bundle/.staging/1.0.0', "Rien ne doit avoir été extrait.");
    }

    public function testInstallWithoutAPinStillWorksForRegistriesThatDoNotPinCommits(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->installedBundles->expects($this->once())->method('upsert');

        $result = $this->service($this->githubZip(self::OTHER))
            ->install('fake-bundle', self::REPO, '1.0.0');

        $this->assertTrue($result->success, (string) $result->error);
    }

    public function testDryRunAlsoRefusesAMovedTag(): void
    {
        $result = $this->service($this->githubZip(self::OTHER))
            ->dryRun('fake-bundle', self::REPO, '1.0.0', self::PINNED);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('installation refusée', (string) $result->error);
    }

    public function testAnArchiveWithNoReadableCommitIsRefusedWhenAPinExists(): void
    {
        $this->installedBundles = $this->createMock(InstalledBundleRepositoryInterface::class);
        $this->installedBundles->expects($this->never())->method('upsert');

        // Archive sans commentaire et dont le dossier racine ne porte aucun sha.
        $path = sys_get_temp_dir() . '/kintai-nocommit-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('plain-folder/bundle.json', '{}');
        $zip->close();

        $result = $this->service($path)->install('fake-bundle', self::REPO, '1.0.0', null, null, self::PINNED);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Impossible de vérifier', (string) $result->error);
    }
}
