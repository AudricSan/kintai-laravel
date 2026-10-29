<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\BackupService;
use kintai\Core\Services\GithubUpdateService;
use kintai\Core\Services\UpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use kintai\Tests\Support\VerifiedReleaseFixture;
use PHPUnit\Framework\TestCase;

final class GithubUpdateServiceTest extends TestCase
{
    use VerifiedReleaseFixture;

    private string $tmpDir;
    private UpdateService $updateService;
    private BackupService $backup;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_ghupdate_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/storage/app', 0775, true);
        mkdir($this->tmpDir . '/storage/backups', 0775, true);
        mkdir($this->tmpDir . '/storage/uploads', 0775, true);
        mkdir($this->tmpDir . '/vendor', 0775, true);
        mkdir($this->tmpDir . '/config', 0775, true);
        file_put_contents($this->tmpDir . '/vendor/should-stay.txt', 'do not touch');

        putenv('KINTAI_STORAGE_PATH=' . $this->tmpDir . '/storage');

        $capsule = kintai_test_database();

        $this->updateService = new UpdateService($this->tmpDir);

        $this->backup = new BackupService($capsule);
        $this->setPrivate($this->backup, 'backupDir', $this->tmpDir . '/storage/backups');

    }

    protected function tearDown(): void
    {
        $this->clearCachedReleases();
        $this->removeDir($this->tmpDir);
        putenv('KINTAI_STORAGE_PATH');
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** La version courante est lue depuis config/app.php (voir UpdateService::getCurrentVersion). */
    private function writeAppVersion(string $version): void
    {
        file_put_contents(
            $this->tmpDir . '/config/app.php',
            '<?php return [\'version\' => ' . var_export($version, true) . '];'
        );
    }

    /** Construit un zip GitHub-style : un seul dossier racine contenant $files. */
    private function makeReleaseZip(string $destZip, array $files): void
    {
        $zip = new \ZipArchive();
        $zip->open($destZip, \ZipArchive::CREATE);
        $root = 'AudricSan-Kintai-abcdef1';
        foreach ($files as $relative => $content) {
            $zip->addFromString($root . '/' . $relative, $content);
        }
        $zip->close();
    }

    private function makeSettings(string $channel = 'release'): AppSettingsService
    {
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn(['update_channel' => $channel]);
        return new AppSettingsService($repo);
    }

    private function makeService(string $tag, array $files, ?string $currentVersion = '0.0.0', string $channel = 'release', bool $prerelease = false, string $targetCommitish = 'main'): GithubUpdateService
    {
        return $this->makeServiceWithReleases([
            [
                'tag_name'         => $tag,
                'zipball_url'      => 'https://example.test/zipball/' . $tag,
                'html_url'         => 'https://example.test/releases/' . $tag,
                'body'             => 'notes for ' . $tag,
                'published_at'     => '2026-01-01T00:00:00Z',
                'prerelease'       => $prerelease,
                'target_commitish' => $targetCommitish,
            ],
        ], $files, $currentVersion, $channel);
    }

    private function makeServiceWithReleases(array $releases, array $files, ?string $currentVersion = '0.0.0', string $channel = 'release'): GithubUpdateService
    {
        if ($currentVersion !== null) {
            $this->writeAppVersion($currentVersion);
        }

        // Releases publiées comme par release.yml : archive vérifiable + SHA256SUMS (audit M10).
        $published = $this->withAssets($releases);
        $releaseFetcher = fn(string $repo, string $token): ?array => $published;

        $zipDownloader = $this->verifiedDownloader($files);

        return new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings($channel),
            $this->tmpDir,
            $releaseFetcher,
            $zipDownloader,
            $this->commitResolver(),
        );
    }

    /** Version du contrat de bundle d'une release, lue dans son app/Contract/Contract.php (avertissement de /admin/update). */
    public function testContractVersionAtReadsTheContractOfTheRelease(): void
    {
        $asked = [];
        $make = function (?string $source) use (&$asked): GithubUpdateService {
            return new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fileFetcher: function (string $repo, string $token, string $tag, string $path) use (&$asked, $source): ?string {
                $asked[] = [$tag, $path];
                return $source;
            },
            );
        };

        $this->assertSame('1.19.0', $make("<?php\nfinal class Contract\n{\n    public const VERSION = '1.19.0';\n}\n")->contractVersionAt('v0.4.0-alpha'));
        $this->assertSame(['v0.4.0-alpha', 'app/Contract/Contract.php'], $asked[0]);
        $this->assertNull($make(null)->contractVersionAt('v0.4.0'), 'fichier illisible');
        $this->assertNull($make('<?php // pas de contrat')->contractVersionAt('v0.1.0'), 'release antérieure au contrat');
    }

    public function testCheckLatestReleaseReturnsNullWhenNoReleaseFound(): void
    {
        $service = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fn(string $repo, string $token): ?array => null,
        );

        $this->assertNull($service->checkLatestRelease());
    }

    public function testCheckLatestReleaseDetectsUpdateAvailable(): void
    {
        $service = $this->makeService('v1.2.0', ['README.md' => 'hello']);

        $info = $service->checkLatestRelease();

        $this->assertNotNull($info);
        $this->assertTrue($info['has_update']);
        $this->assertSame('1.2.0', $info['latest_version']);
        $this->assertSame('0.0.0', $info['current_version']);
    }

    public function testCheckLatestReleaseNoUpdateWhenAlreadyCurrent(): void
    {
        $service = $this->makeService('v1.0.0', ['README.md' => 'hello'], currentVersion: '1.0.0');

        $info = $service->checkLatestRelease();

        $this->assertFalse($info['has_update']);
    }

    /**
     * Régression : config/app.php ne conserve jamais que la ligne "X.Y.0"
     * (voir wiki : Releasing-a-Version) — une instance jamais mise à jour via
     * l'auto-updater affiche donc ce placeholder, alors que le tag alpha/beta
     * réel de la ligne porte un Z non nul. La comparaison doit tout de même
     * détecter la mise à jour.
     */
    public function testCheckLatestReleaseDetectsUpdateWhenCurrentIsLinePlaceholder(): void
    {
        $service = $this->makeService(
            'v0.11.3',
            ['README.md' => 'hello'],
            currentVersion: '0.11.0',
            channel: 'alpha',
            prerelease: true,
            targetCommitish: 'alpha',
        );

        $info = $service->checkLatestRelease();

        $this->assertNotNull($info);
        $this->assertTrue($info['has_update']);
        $this->assertSame('0.11.3', $info['latest_version']);
    }

    public function testCheckLatestReleaseDetectsUpdateBetweenTwoIterationsOfSameLine(): void
    {
        $service = $this->makeService(
            'v0.11.3',
            ['README.md' => 'hello'],
            currentVersion: '0.11.2',
            channel: 'alpha',
            prerelease: true,
            targetCommitish: 'alpha',
        );

        $info = $service->checkLatestRelease();

        $this->assertTrue($info['has_update']);
    }

    public function testReleaseChannelIgnoresAlphaAndBetaTags(): void
    {
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v2.0.0-c3', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'alpha'],
            ['tag_name' => 'v1.5.0-c2', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'z', 'prerelease' => false, 'target_commitish' => 'main'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'release');

        $info = $service->checkLatestRelease();

        $this->assertNotNull($info);
        $this->assertSame('1.0.0', $info['latest_version']);
    }

    public function testBetaChannelPrefersHighestStableOrBetaTag(): void
    {
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v2.0.0-c1', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'alpha'],
            ['tag_name' => 'v1.5.0-c2', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'z', 'prerelease' => false, 'target_commitish' => 'main'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'beta');

        $info = $service->checkLatestRelease();

        $this->assertNotNull($info);
        $this->assertSame('1.5.0-c2', $info['latest_version']);
    }

    public function testAlphaChannelPrefersHighestTagOverall(): void
    {
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v2.0.0-c1', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'alpha'],
            ['tag_name' => 'v1.5.0-c2', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'z', 'prerelease' => false, 'target_commitish' => 'main'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'alpha');

        $info = $service->checkLatestRelease();

        $this->assertNotNull($info);
        $this->assertSame('2.0.0-c1', $info['latest_version']);
    }

    public function testReleaseChannelReturnsNullWhenOnlyPrereleasesExist(): void
    {
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v1.0.0-c1', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'release');

        $this->assertNull($service->checkLatestRelease());
    }

    public function testCheckLatestReleasePaginatesPastPrereleasesToFindStableRelease(): void
    {
        // Simule une longue série d'alpha/beta plus récente qu'une release
        // stable : la release stable ne doit pas être perdue simplement
        // parce qu'elle se trouve sur une page suivante de l'API GitHub.
        $prereleasePage = array_map(
            fn(int $i): array => ['tag_name' => "v9.{$i}.0-c1", 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
            range(1, 100)
        );
        $stablePage = [
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'z', 'prerelease' => false, 'target_commitish' => 'main'],
        ];

        $this->writeAppVersion('0.0.0');
        $calls = [];
        $releaseFetcher = function (string $repo, string $token, int $page) use (&$calls, $prereleasePage, $stablePage): ?array {
            $calls[] = $page;
            return match ($page) {
                1 => $prereleasePage,
                2 => $stablePage,
                default => [],
            };
        };

        $service = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings('release'),
            $this->tmpDir,
            $releaseFetcher,
        );

        $info = $service->checkLatestRelease();

        $this->assertNotNull($info);
        $this->assertSame('1.0.0', $info['latest_version']);
        $this->assertSame([1, 2], $calls);
    }

    public function testApplyUpdateReturnsErrorWhenNoUpdateAvailable(): void
    {
        $service = $this->makeService('v1.0.0', ['README.md' => 'hello'], currentVersion: '1.0.0');

        $result = $service->applyUpdate();

        $this->assertFalse($result['ok']);
        $this->assertSame('no_update_available', $result['error']);
    }

    public function testApplyUpdateReturnsErrorWhenDownloadFails(): void
    {
        $service = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fn(string $repo, string $token): ?array => $this->withAssets([
                [
                    'tag_name'         => 'v1.0.0',
                    'zipball_url'      => 'https://example.test/zipball/v1.0.0',
                    'target_commitish' => 'main',
                ],
            ]),
            fn(string $url, string $dest, string $token): bool => false,
        );

        $result = $service->applyUpdate();

        $this->assertFalse($result['ok']);
        $this->assertSame('download_failed', $result['error']);
    }

    public function testApplyUpdateCopiesNewFilesAndBumpsVersion(): void
    {
        // Comme en conditions réelles, la release embarque un config/app.php
        // dont la version par défaut a été bumpée : c'est ce fichier, une fois
        // synchronisé, qui fait foi pour UpdateService::getCurrentVersion().
        $service = $this->makeService('v1.0.0', [
            'README.md'    => 'v1 readme',
            'src/Foo.php'  => '<?php // foo v1',
            'config/app.php' => "<?php return ['version' => '1.0.0'];",
        ]);

        $result = $service->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertSame('1.0.0', $result['version']);
        // 3 fichiers + release-manifest.json (tag et commit de la version installée).
        $this->assertSame(4, $result['files_copied']);
        $this->assertSame(0, $result['files_deleted']);
        $this->assertSame('1.0.0', $this->updateService->getCurrentVersion());
        $this->assertFileExists($this->tmpDir . '/README.md');
        $this->assertFileExists($this->tmpDir . '/src/Foo.php');
        $this->assertSame('<?php // foo v1', file_get_contents($this->tmpDir . '/src/Foo.php'));
    }

    /**
     * Régression : appliquer une prerelease dont le Z réel dépasse le
     * placeholder "X.Y.0" synchronisé dans config/app.php ne doit pas laisser
     * l'instance se croire perpétuellement en retard sur cette même release
     * (voir UpdateService::setCurrentVersion()).
     */
    public function testCheckLatestReleaseIsUpToDateRightAfterApplyingAnIteratedRelease(): void
    {
        $service = $this->makeService(
            'v0.11.10',
            ['README.md' => 'hello', 'config/app.php' => "<?php return ['version' => '0.11.0'];"],
            currentVersion: '0.11.9',
            channel: 'alpha',
            prerelease: true,
            targetCommitish: 'alpha',
        );

        $this->assertTrue($service->applyUpdate()['ok']);

        $info = $service->checkLatestRelease();
        $this->assertFalse($info['has_update']);
        $this->assertSame('0.11.10', $info['current_version']);
    }

    public function testApplyUpdateDeletesFilesRemovedFromNewRelease(): void
    {
        $first = $this->makeService('v1.0.0', [
            'README.md'   => 'v1 readme',
            'src/Foo.php' => '<?php // foo v1',
        ]);
        $first->applyUpdate();
        $this->assertFileExists($this->tmpDir . '/src/Foo.php');

        // v2 supprime src/Foo.php (remplacé par src/Bar.php) et modifie README.md
        $second = $this->makeService('v2.0.0', [
            'README.md'   => 'v2 readme',
            'src/Bar.php' => '<?php // bar v2',
        ], currentVersion: null);

        $result = $second->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['files_deleted']);
        $this->assertFileDoesNotExist($this->tmpDir . '/src/Foo.php');
        $this->assertFileExists($this->tmpDir . '/src/Bar.php');
        $this->assertSame('v2 readme', file_get_contents($this->tmpDir . '/README.md'));
    }

    public function testApplyUpdateNeverDeletesExcludedPaths(): void
    {
        // Simule un manifeste corrompu qui référencerait des fichiers locaux — la liste d'exclusion doit les
        // protéger même dans ce cas.
        @mkdir($this->tmpDir . '/storage/app', 0755, true);
        file_put_contents($this->tmpDir . '/storage/app/should-stay.txt', 'data');
        file_put_contents($this->tmpDir . '/.env', 'SECRET=1');
        file_put_contents(
            $this->tmpDir . '/storage/app/update-files-manifest.json',
            json_encode(['storage/app/should-stay.txt', '.env', 'README.md'])
        );

        $service = $this->makeService('v1.0.0', ['README.md' => 'v1 readme']);
        $result = $service->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertFileExists($this->tmpDir . '/storage/app/should-stay.txt');
        $this->assertFileExists($this->tmpDir . '/.env');
    }

    public function testFirstRunNeverDeletesAnything(): void
    {
        // Sans manifeste préexistant, aucune suppression n'est possible même
        // si la release ne contient qu'un sous-ensemble de fichiers.
        file_put_contents($this->tmpDir . '/pre-existing.txt', 'kept');

        $service = $this->makeService('v1.0.0', ['README.md' => 'v1 readme']);
        $result = $service->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['files_deleted']);
        $this->assertFileExists($this->tmpDir . '/pre-existing.txt');
    }

    public function testApplyUpdateReportsIncreasingProgressUpTo100(): void
    {
        $service = $this->makeService('v1.0.0', [
            'README.md'   => 'v1 readme',
            'src/Foo.php' => '<?php // foo v1',
        ]);

        $percents = [];
        $result = $service->applyUpdate(function (int $percent, string $label) use (&$percents): void {
            $percents[] = $percent;
        });

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($percents);
        $this->assertSame(0, $percents[0]);
        $this->assertSame(100, end($percents));

        // La suite doit être croissante (non strictement, la synchronisation
        // peut émettre plusieurs fois le même pourcentage arrondi).
        $sorted = $percents;
        sort($sorted);
        $this->assertSame($sorted, $percents);
    }

    public function testApplyUpdateReportsFileSyncProgress(): void
    {
        // 3 fichiers dans la release : la synchronisation doit rapporter une
        // progression granulaire dans la bande 55-75%, jusqu'à 75% (3/3).
        $service = $this->makeService('v1.0.0', [
            'README.md'   => 'v1 readme',
            'src/Foo.php' => '<?php // foo v1',
            'src/Bar.php' => '<?php // bar v1',
        ]);

        $percents = [];
        $service->applyUpdate(function (int $percent, string $label) use (&$percents): void {
            $percents[] = $percent;
        });

        $syncPercents = array_filter($percents, fn(int $p) => $p > 55 && $p <= 75);
        $this->assertNotEmpty($syncPercents);
        $this->assertContains(75, $percents);
    }

    public function testGetLastCheckErrorIsNullAfterSuccessfulCheck(): void
    {
        $service = $this->makeService('v1.2.0', ['README.md' => 'hello']);

        $service->checkLatestRelease();

        $this->assertNull($service->getLastCheckError());
    }

    public function testGetLastCheckErrorIsNullWhenChannelHasNoCompatibleRelease(): void
    {
        // Aucune release ne correspond au canal : ce n'est pas un échec technique,
        // juste l'absence de release compatible — ne doit pas remonter d'erreur.
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v1.0.0-c1', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'release');

        $service->checkLatestRelease();

        $this->assertNull($service->getLastCheckError());
    }

    public function testGetLastCheckErrorReportsGenericFailureWhenFetcherReturnsNull(): void
    {
        $service = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fn(string $repo, string $token): ?array => null,
        );

        $service->checkLatestRelease();

        $this->assertNotNull($service->getLastCheckError());
    }

    public function testGetLastCheckErrorReportsMissingRepoConfig(): void
    {
        $service = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fn(string $repo, string $token): ?array => [['tag_name' => 'v1.0.0', 'zipball_url' => 'z']],
        );
        $this->setPrivate($service, 'repo', '');

        $this->assertNull($service->checkLatestRelease());
        $this->assertNotNull($service->getLastCheckError());
    }

    public function testCondenseReleaseNotesKeepsOnlyFirstBulletPerCategory(): void
    {
        $service = $this->makeService('v1.0.0', ['README.md' => 'hello']);

        $notes = <<<MD
            ### Added
            - first added item
            - second added item

            ### Fixed
            - first fixed item
            - second fixed item
            - third fixed item
            MD;

        $condensed = $service->condenseReleaseNotes($notes);

        $this->assertSame(
            "### Added\n- first added item\n\n### Fixed\n- first fixed item",
            $condensed
        );
    }

    public function testCondenseReleaseNotesReturnsEmptyStringForBlankInput(): void
    {
        $service = $this->makeService('v1.0.0', ['README.md' => 'hello']);

        $this->assertSame('', $service->condenseReleaseNotes(''));
        $this->assertSame('', $service->condenseReleaseNotes("   \n  "));
    }

    public function testGetRepoReleasesUrlUsesConfiguredRepo(): void
    {
        $service = $this->makeService('v1.0.0', ['README.md' => 'hello']);

        $this->assertSame('https://github.com/AudricSan/Kintai/releases', $service->getRepoReleasesUrl());
    }

    public function testGetReleaseHistoryReturnsReleasesSortedNewestFirst(): void
    {
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'z', 'html_url' => 'https://example.test/r/1.0.0', 'body' => 'notes 1.0.0', 'published_at' => '2026-01-01T00:00:00Z', 'prerelease' => false, 'target_commitish' => 'main'],
            ['tag_name' => 'v1.2.0', 'zipball_url' => 'z', 'html_url' => 'https://example.test/r/1.2.0', 'body' => 'notes 1.2.0', 'published_at' => '2026-02-01T00:00:00Z', 'prerelease' => false, 'target_commitish' => 'main'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'release');

        $history = $service->getReleaseHistory('release');

        $this->assertSame(['1.2.0', '1.0.0'], array_column($history, 'version'));
        $this->assertSame('notes 1.2.0', $history[0]['notes']);
        $this->assertSame('https://example.test/r/1.2.0', $history[0]['release_url']);
        $this->assertSame('2026-02-01T00:00:00Z', $history[0]['published_at']);
    }

    public function testGetReleaseHistoryFiltersOutIncompatibleChannels(): void
    {
        $service = $this->makeServiceWithReleases([
            ['tag_name' => 'v2.0.0-c1', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'alpha'],
            ['tag_name' => 'v1.5.0-c2', 'zipball_url' => 'z', 'prerelease' => true, 'target_commitish' => 'beta'],
            ['tag_name' => 'v1.0.0', 'zipball_url' => 'z', 'prerelease' => false, 'target_commitish' => 'main'],
        ], ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'release');

        $history = $service->getReleaseHistory('release');

        $this->assertSame(['1.0.0'], array_column($history, 'version'));
    }

    public function testGetReleaseHistoryRespectsLimit(): void
    {
        $releases = array_map(
            fn(int $i): array => ['tag_name' => "v1.{$i}.0", 'zipball_url' => 'z', 'prerelease' => false, 'target_commitish' => 'main'],
            range(0, 9)
        );
        $service = $this->makeServiceWithReleases($releases, ['README.md' => 'hello'], currentVersion: '0.0.0', channel: 'release');

        $history = $service->getReleaseHistory('release', 3);

        $this->assertCount(3, $history);
        $this->assertSame(['1.9.0', '1.8.0', '1.7.0'], array_column($history, 'version'));
    }

    public function testGetReleaseHistoryReturnsEmptyArrayWhenFetchFails(): void
    {
        $service = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fn(string $repo, string $token): ?array => null,
        );

        $this->assertSame([], $service->getReleaseHistory('release'));
    }

    #[DataProvider('releaseListValidationProvider')]
    public function testIsValidReleaseListRejectsGithubErrorObjects(mixed $data, bool $expected): void
    {
        $ref = new \ReflectionMethod(GithubUpdateService::class, 'isValidReleaseList');
        $ref->setAccessible(true);

        $this->assertSame($expected, $ref->invoke(null, $data));
    }

    public static function releaseListValidationProvider(): array
    {
        return [
            'valid release list' => [[['tag_name' => 'v1.0.0']], true],
            'empty list'         => [[], true],
            // Forme réelle d'une réponse GitHub rate-limitée : un objet JSON
            // (donc un tableau associatif après json_decode), pas une liste.
            'rate limit error object' => [['message' => 'API rate limit exceeded for x.x.x.x.', 'documentation_url' => 'https://docs.github.com/rest'], false],
            'not found error object'  => [['message' => 'Not Found'], false],
            'null response'           => [null, false],
            'scalar response'         => ['API rate limit exceeded', false],
        ];
    }

    // -------------------------------------------------------------------------
    // Intégrité de l'archive (audit du 13/10/2026, M10)
    // -------------------------------------------------------------------------

    private function serviceWith(array $releases, \Closure $downloader, ?\Closure $commitResolver = null): GithubUpdateService
    {
        $this->writeAppVersion('0.0.0');
        return new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->makeSettings(),
            $this->tmpDir,
            fn(string $repo, string $token): ?array => $releases,
            $downloader,
            $commitResolver ?? $this->commitResolver(),
        );
    }

    private function release(string $tag, bool $withAssets = true): array
    {
        $release = ['tag_name' => $tag, 'zipball_url' => 'https://example.test/zipball/' . $tag, 'target_commitish' => 'main'];
        return $withAssets ? $this->withAssets([$release])[0] : $release;
    }

    /** Une release sans archive vérifiable (antérieure au mécanisme) n'est pas appliquée, même son zipball. */
    public function testAReleaseWithoutTheVerifiedArchiveIsRefused(): void
    {
        $downloads = 0;
        $service = $this->serviceWith([$this->release('v1.0.0', false)], function () use (&$downloads): bool {
            $downloads++;
            return true;
        });

        $result = $service->applyUpdate();

        $this->assertSame(['ok' => false, 'error' => 'release_archive_missing'], $result);
        $this->assertSame(0, $downloads);
        $this->assertFileDoesNotExist($this->tmpDir . '/README.md');
    }

    public function testAnArchiveWhoseChecksumDiffersIsRefusedBeforeAnyFileIsWritten(): void
    {
        $genuine = $this->verifiedDownloader(['README.md' => 'v1']);
        // L'archive servie est altérée après publication de l'empreinte.
        $tampered = function (string $url, string $dest, string $token) use ($genuine): bool {
            $ok = $genuine($url, $dest, $token);
            if (str_ends_with($url, '/archive')) {
                file_put_contents($dest, 'x', FILE_APPEND);
            }
            return $ok;
        };

        $result = $this->serviceWith([$this->release('v1.0.0')], $tampered)->applyUpdate();

        $this->assertSame(['ok' => false, 'error' => 'checksum_failed'], $result);
        $this->assertFileDoesNotExist($this->tmpDir . '/README.md');
        $this->assertSame('0.0.0', $this->updateService->getCurrentVersion());
    }

    public function testAnArchiveBuiltFromAnotherCommitIsRefused(): void
    {
        $downloader = $this->verifiedDownloader(['README.md' => 'v1'], null, str_repeat('f', 40));

        $result = $this->serviceWith([$this->release('v1.0.0')], $downloader)->applyUpdate();

        $this->assertSame(['ok' => false, 'error' => 'commit_mismatch'], $result);
        $this->assertFileDoesNotExist($this->tmpDir . '/README.md');
    }

    public function testAnUnresolvableTagCommitIsRefused(): void
    {
        $service = $this->serviceWith(
            [$this->release('v1.0.0')],
            $this->verifiedDownloader(['README.md' => 'v1']),
            fn(string $repo, string $token, string $tag): ?string => null,
        );

        $this->assertSame('commit_mismatch', $service->applyUpdate()['error']);
    }

    public function testComposerIsNoLongerRunAndVendorComesFromTheArchive(): void
    {
        $service = $this->makeService('v1.0.0', ['vendor/autoload.php' => '<?php // v1', 'composer.lock' => '{"v":1}']);

        $result = $service->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertSame('bundled', $result['composer']);
        $this->assertArrayNotHasKey('composer_output', $result);
        $this->assertSame('<?php // v1', file_get_contents($this->tmpDir . '/vendor/autoload.php'));
    }

    /** Les paquets retirés d'une version à l'autre disparaissent de vendor/ (fichiers posés par une mise à jour). */
    public function testVendorFilesDroppedByTheNewReleaseAreRemoved(): void
    {
        $this->makeService('v1.0.0', ['vendor/old/pkg.php' => 'old', 'README.md' => 'v1'])->applyUpdate();
        $this->assertFileExists($this->tmpDir . '/vendor/old/pkg.php');

        $result = $this->makeService('v2.0.0', ['README.md' => 'v2'], currentVersion: '1.0.0')->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertFileDoesNotExist($this->tmpDir . '/vendor/old/pkg.php');
    }

    public function testLocalDataShippedByMistakeInTheArchiveIsNeverCopied(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'SECRET=local');

        $result = $this->makeService('v1.0.0', ['README.md' => 'v1', '.env' => 'SECRET=archive', 'storage/app/database.sqlite' => 'x'])->applyUpdate();

        $this->assertTrue($result['ok']);
        $this->assertSame('SECRET=local', file_get_contents($this->tmpDir . '/.env'));
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/app/database.sqlite');
    }

    public function testChecksumParsing(): void
    {
        $file = $this->tmpDir . '/a.zip';
        file_put_contents($file, 'abc');
        $hash = hash('sha256', 'abc');

        $this->assertTrue(GithubUpdateService::checksumMatches($file, $hash . '  a.zip' . "\n", 'a.zip'));
        $this->assertTrue(GithubUpdateService::checksumMatches($file, strtoupper($hash) . ' *a.zip', 'a.zip'), 'mode binaire de sha256sum');
        $this->assertFalse(GithubUpdateService::checksumMatches($file, $hash . '  autre.zip', 'a.zip'));
        $this->assertFalse(GithubUpdateService::checksumMatches($file, str_repeat('0', 64) . '  a.zip', 'a.zip'));
        $this->assertFalse(GithubUpdateService::checksumMatches($file, '', 'a.zip'));
    }

    public function testManifestMustNameTheSameTag(): void
    {
        $root = $this->tmpDir . '/release';
        @mkdir($root, 0755, true);
        file_put_contents($root . '/' . GithubUpdateService::RELEASE_MANIFEST, json_encode(['tag' => 'v1.0.0', 'commit' => self::RELEASE_COMMIT]));

        $this->assertTrue(GithubUpdateService::manifestMatches($root, 'v1.0.0', self::RELEASE_COMMIT));
        $this->assertFalse(GithubUpdateService::manifestMatches($root, 'v2.0.0', self::RELEASE_COMMIT));
        $this->assertFalse(GithubUpdateService::manifestMatches($root, 'v1.0.0', str_repeat('a', 40)));
        $this->assertFalse(GithubUpdateService::manifestMatches($this->tmpDir . '/absent', 'v1.0.0', self::RELEASE_COMMIT));
    }
}
