<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Container;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\JsonLanguageRepository;
use kintai\Core\Repositories\JsonTranslationRepository;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\BackupService;
use kintai\Core\Services\GithubUpdateService;
use kintai\Core\Services\TranslationService;
use kintai\Core\Services\UpdateService;
use kintai\UI\Controller\Web\System\BackupController;
use kintai\UI\ViewRenderer;
use kintai\Tests\Support\VerifiedReleaseFixture;
use PHPUnit\Framework\TestCase;

final class BackupControllerTest extends TestCase
{
    use VerifiedReleaseFixture;

    private string $tmpDir;
    private UpdateService $updateService;
    private BackupService $backup;
    private MigrationRunner $migrator;
    private AppSettingsService $settings;
    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_backupctrl_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/storage/app', 0775, true);
        mkdir($this->tmpDir . '/storage/backups', 0775, true);
        mkdir($this->tmpDir . '/storage/uploads', 0775, true);
        mkdir($this->tmpDir . '/config', 0775, true);

        putenv('KINTAI_STORAGE_PATH=' . $this->tmpDir . '/storage');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        mkdir($this->tmpDir . '/no-migrations', 0775, true);
        $this->migrator = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $this->setPrivate($this->migrator, 'capsule', $capsule);
        $this->setPrivate($this->migrator, 'migrationsPath', $this->tmpDir . '/no-migrations');

        $this->updateService = new UpdateService($this->tmpDir);

        $this->backup = new BackupService($capsule);
        $this->setPrivate($this->backup, 'backupDir', $this->tmpDir . '/storage/backups');

        $settingsRepo = $this->createStub(AppSettingsRepositoryInterface::class);
        $settingsRepo->method('get')->willReturn(null);
        $settingsRepo->method('all')->willReturn([]);
        $this->settings = new AppSettingsService($settingsRepo);

        $this->view = new ViewRenderer(sys_get_temp_dir());


        // describeFlash() traduit via __() : sans ça, __() (voir helpers.php) retombe
        // silencieusement sur la clé brute faute de TranslationService dans le container,
        // ce qui masquerait exactement le bug que ce test vérifie (interpolation :filename
        // etc. jamais appliquée si __() ne fait que renvoyer la clé).
        $langPath = dirname(__DIR__, 5) . '/lang';
        $translationService = new TranslationService(
            new JsonTranslationRepository($langPath),
            new JsonLanguageRepository($langPath),
        );
        $translationService->setLocale('fr');
        Container::getInstance()->instance(TranslationService::class, $translationService);
    }

    protected function tearDown(): void
    {
        $this->clearCachedReleases();
        $this->removeDir($this->tmpDir);
        putenv('KINTAI_STORAGE_PATH');
        $_GET = [];
        \kintai\Core\Services\Log::reset();

        $instancesProp = new \ReflectionProperty(Container::class, 'instances');
        $instancesProp->setAccessible(true);
        $instances = $instancesProp->getValue(Container::getInstance());
        unset($instances[TranslationService::class]);
        $instancesProp->setValue(Container::getInstance(), $instances);
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

    private function makeController(?\Closure $releaseFetcher = null, ?\Closure $zipDownloader = null): BackupController
    {
        $githubUpdate = new GithubUpdateService(
            $this->updateService,
            $this->backup,
            $this->migrator,
            $this->settings,
            $this->tmpDir,
            // Releases publiées comme par release.yml : archive vérifiable + SHA256SUMS (audit M10).
            $releaseFetcher === null ? null : function (string $repo, string $token) use ($releaseFetcher): ?array {
                $releases = $releaseFetcher($repo, $token);
                return $releases === null ? null : $this->withAssets($releases);
            },
            $zipDownloader,
            $this->commitResolver(),
        );

        return new BackupController($this->view, $this->backup, $this->updateService, $githubUpdate, $this->migrator, $this->settings, new AuditLogger());
    }

    private function requestAs(bool $isAdmin): Request
    {
        $request = new Request();
        $request->setAttribute('auth_user', ['id' => 1, 'is_admin' => $isAdmin]);
        return $request;
    }

    private function locationOf(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');
        $ref->setAccessible(true);
        return $ref->getValue($response)['Location'] ?? '';
    }

    /** @return array{type: 'success'|'danger', text: string}|null */
    private function describeFlash(BackupController $controller, string $raw): ?array
    {
        $method = new \ReflectionMethod($controller, 'describeFlash');
        $method->setAccessible(true);
        return $method->invoke($controller, $raw);
    }

    // Le rejet non-Owner est désormais couvert par OwnerOnlyMiddlewareTest
    // (route-level, RBAC-V2) — le contrôleur ne se garde plus lui-même.

    public function testCreateAllowsOwner(): void
    {
        $controller = $this->makeController();

        $response = $controller->create($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=created_', $this->locationOf($response));
    }

    public function testIndexExposesBackupSettingsToTheView(): void
    {
        foreach (['system', 'layout'] as $dir) {
            $path = sys_get_temp_dir() . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
        touch(sys_get_temp_dir() . '/system/backup.php');
        touch(sys_get_temp_dir() . '/layout/app.php');

        $controller = $this->makeController();
        $this->settings->setMany(['backup_auto_enabled' => '0', 'backup_max_keep' => '5']);

        $response = $controller->index($this->requestAs(true));

        $this->assertSame(200, $response->status());
    }

    public function testDownloadRedirectsWhenFileMissing(): void
    {
        $controller = $this->makeController();

        $_GET = ['filename' => 'does_not_exist.zip'];
        $response = $controller->download($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=error_not_found', $this->locationOf($response));
    }

    public function testDownloadStreamsExistingBackup(): void
    {
        $controller = $this->makeController();
        $created = $controller->create($this->requestAs(true));
        $filename = urldecode(substr($this->locationOf($created), strlen('/admin/backup?success=created_')));

        $_GET = ['filename' => $filename];
        $response = $controller->download($this->requestAs(true));

        $this->assertSame(200, $response->status());
    }

    public function testUpdateReturnsErrorWhenNoUpdateAvailable(): void
    {
        $this->writeAppVersion('1.0.0');
        $controller = $this->makeController(
            fn(string $repo, string $token): ?array => [[
                'tag_name'    => 'v1.0.0',
                'zipball_url' => 'https://example.test/zipball/v1.0.0',
            ]],
        );

        $response = $controller->update($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=error_no_update_available', $this->locationOf($response));
    }

    public function testUpdateAppliesReleaseAndRedirectsWithSummary(): void
    {
        $this->writeAppVersion('1.0.0');
        $controller = $this->makeController(
            fn(string $repo, string $token): ?array => [[
                'tag_name'    => 'v2.0.0',
                'zipball_url' => 'https://example.test/zipball/v2.0.0',
                'html_url'    => 'https://example.test/releases/v2.0.0',
                'body'        => 'notes',
                'published_at' => '2026-01-01T00:00:00Z',
                'target_commitish' => 'main',
            ]],
            $this->verifiedDownloader([
                'README.md'    => 'v2',
                'config/app.php' => "<?php return ['version' => '2.0.0'];",
            ]),
        );

        $response = $controller->update($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $location = $this->locationOf($response);
        // 2 fichiers + release-manifest.json de l'archive vérifiée.
        $this->assertStringStartsWith('/admin/update?success=updated_2.0.0_files-3_deleted-0', $location);
        $this->assertSame('2.0.0', $this->updateService->getCurrentVersion());
        $this->assertFileExists($this->tmpDir . '/README.md');
        $this->assertFalse($this->settings->maintenanceModeEnabled());
    }

    public function testUpdateEnablesMaintenanceModeDuringApplyThenDisablesIt(): void
    {
        $this->writeAppVersion('1.0.0');
        $enabledDuringApply = null;
        $controller = $this->makeController(
            fn(string $repo, string $token): ?array => [[
                'tag_name'    => 'v2.0.0',
                'zipball_url' => 'https://example.test/zipball/v2.0.0',
                'target_commitish' => 'main',
            ]],
            $this->verifiedDownloader(
                ['config/app.php' => "<?php return ['version' => '2.0.0'];"],
                function () use (&$enabledDuringApply): void {
                    $enabledDuringApply = $this->settings->maintenanceModeEnabled();
                },
            ),
        );

        $this->assertFalse($this->settings->maintenanceModeEnabled());

        $controller->update($this->requestAs(true));

        $this->assertTrue($enabledDuringApply);
        $this->assertFalse($this->settings->maintenanceModeEnabled());
    }

    public function testUpdateLeavesMaintenanceModeOnIfAlreadyEnabledBeforehand(): void
    {
        $this->writeAppVersion('1.0.0');
        $this->settings->setMany(['maintenance_mode_enabled' => '1']);
        $controller = $this->makeController(
            fn(string $repo, string $token): ?array => [[
                'tag_name'    => 'v2.0.0',
                'zipball_url' => 'https://example.test/zipball/v2.0.0',
                'target_commitish' => 'main',
            ]],
            $this->verifiedDownloader(['config/app.php' => "<?php return ['version' => '2.0.0'];"]),
        );

        $controller->update($this->requestAs(true));

        $this->assertTrue($this->settings->maintenanceModeEnabled());
    }

    public function testMigrateRedirectsToUpdatePage(): void
    {
        $controller = $this->makeController();

        $response = $controller->migrate($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringStartsWith('/admin/update?success=migrated', $this->locationOf($response));
        $this->assertFalse($this->settings->maintenanceModeEnabled());
    }

    public function testSaveSettingsPersistsAutoEnabledAndMaxKeep(): void
    {
        $controller = $this->makeController();

        $_POST = ['backup_auto_enabled' => '1', 'backup_max_keep' => '10'];
        $response = $controller->saveSettings($this->requestAs(true));
        $_POST = [];

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=settings_saved', $this->locationOf($response));
        $this->assertTrue($this->settings->backupAutoEnabled());
        $this->assertSame(10, $this->settings->backupMaxKeep());
    }

    public function testSaveSettingsDisablesAutoWhenCheckboxUnchecked(): void
    {
        $controller = $this->makeController();
        $this->settings->setMany(['backup_auto_enabled' => '1']);

        $_POST = ['backup_max_keep' => '0', 'backup_auto_enabled' => '0'];
        $controller->saveSettings($this->requestAs(true));
        $_POST = [];

        $this->assertFalse($this->settings->backupAutoEnabled());
    }

    public function testSaveSettingsClampsMaxKeepToValidRange(): void
    {
        $controller = $this->makeController();

        $_POST = ['backup_auto_enabled' => '1', 'backup_max_keep' => '9999'];
        $controller->saveSettings($this->requestAs(true));
        $_POST = [];

        $this->assertSame(365, $this->settings->backupMaxKeep());
    }

    public function testSaveChannelPersistsValidChannel(): void
    {
        $controller = $this->makeController();

        $_POST = ['channel' => 'alpha'];
        $response = $controller->saveChannel($this->requestAs(true));
        $_POST = [];

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=channel_alpha', $this->locationOf($response));
        $this->assertSame('alpha', $this->settings->updateChannel());
    }

    public function testSaveChannelFallsBackToReleaseForInvalidValue(): void
    {
        $controller = $this->makeController();

        $_POST = ['channel' => 'nightly'];
        $response = $controller->saveChannel($this->requestAs(true));
        $_POST = [];

        $this->assertStringContainsString('success=channel_release', $this->locationOf($response));
        $this->assertSame('release', $this->settings->updateChannel());
    }

    public function testDescribeFlashReturnsNullForEmptyValue(): void
    {
        $flash = $this->describeFlash($this->makeController(), '');

        $this->assertNull($flash);
    }

    /**
     * Régression : avant ce correctif, le code brut (?success=error_...) était affiché
     * tel quel dans une boîte stylée "info" (neutre), y compris le message d'exception
     * brut pour les échecs génériques — voir CHANGELOG. describeFlash() doit maintenant
     * toujours renvoyer 'danger' pour tout code préfixé "error_", et ne jamais laisser
     * le préfixe "error_" lui-même apparaître dans le texte affiché.
     */
    public function testDescribeFlashMarksEveryErrorCodeAsDanger(): void
    {
        $controller = $this->makeController();

        foreach (['error_no_file', 'error_not_found', 'error_no_update_available', 'error_download_failed', 'error_extract_failed', 'error_' . urlencode('SQLSTATE[HY000]: disk I/O error')] as $code) {
            $flash = $this->describeFlash($controller, $code);
            $this->assertNotNull($flash, $code);
            $this->assertSame('danger', $flash['type'], $code);
            $this->assertStringNotContainsStringIgnoringCase('error_', $flash['text'], $code);
        }
    }

    public function testDescribeFlashNeverShowsFreeTextFromTheUrl(): void
    {
        // N'importe qui peut fabriquer ces liens : aucun texte venu de l'URL ne doit apparaître dans un bandeau.
        $controller = $this->makeController();

        $error = $this->describeFlash($controller, 'error_' . urlencode('Votre compte est suspendu, appelez le 0800'));
        $this->assertSame('danger', $error['type']);
        $this->assertStringNotContainsString('suspendu', $error['text']);

        $this->assertNull($this->describeFlash($controller, urlencode('Votre compte est suspendu')));
        $this->assertNull($this->describeFlash($controller, 'created_' . urlencode('<b>piège</b>')));
        $this->assertNull($this->describeFlash($controller, 'channel_' . urlencode('appelez le 0800')));
        $this->assertNull($this->describeFlash($controller, 'deleted_all_' . urlencode('beaucoup')));
    }

    public function testDescribeFlashShowsNothingForAFailureWhoseDetailIsInTheSession(): void
    {
        // Le détail est affiché par le layout depuis kintai\Core\SessionFlash : pas de second bandeau.
        $this->assertNull($this->describeFlash($this->makeController(), 'error_failed'));
    }

    public function testDescribeFlashMarksKnownSuccessCodesAsSuccess(): void
    {
        $controller = $this->makeController();

        foreach (['restored', 'deleted', 'migrated', 'created_backup-2026-08-05.zip', 'deleted_all_3', 'channel_alpha', 'settings_saved'] as $code) {
            $flash = $this->describeFlash($controller, $code);
            $this->assertNotNull($flash, $code);
            $this->assertSame('success', $flash['type'], $code);
        }
    }

    public function testDescribeFlashInterpolatesDynamicValues(): void
    {
        $controller = $this->makeController();

        $this->assertStringContainsString('backup-2026-08-05.zip', $this->describeFlash($controller, 'created_backup-2026-08-05.zip')['text']);
        $this->assertStringContainsString('3', $this->describeFlash($controller, 'deleted_all_3')['text']);
        $this->assertStringContainsString('alpha', $this->describeFlash($controller, 'channel_alpha')['text']);
    }

    public function testRestoreLogsBackupRestoredAction(): void
    {
        $logs = $this->createMock(\kintai\Core\Repositories\LogRepositoryInterface::class);
        $container = new Container();
        $container->instance(\kintai\Core\Repositories\LogRepositoryInterface::class, $logs);
        \kintai\Core\Services\Log::setContainer($container);

        $controller = $this->makeController();
        $created = $controller->create($this->requestAs(true));
        $filename = urldecode(substr($this->locationOf($created), strlen('/admin/backup?success=created_')));

        $logs->expects($this->atLeastOnce())
            ->method('record')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->callback(
                fn($action) => in_array($action, ['backup.created', 'backup.restored'], true)
            ), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything());

        $_POST = ['filename' => $filename];
        $response = $controller->restore($this->requestAs(true));
        $_POST = [];

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=restored', $this->locationOf($response));

        \kintai\Core\Services\Log::reset();
    }

    public function testDeleteLogsBackupDeletedAction(): void
    {
        $logs = $this->createMock(\kintai\Core\Repositories\LogRepositoryInterface::class);
        $container = new Container();
        $container->instance(\kintai\Core\Repositories\LogRepositoryInterface::class, $logs);
        \kintai\Core\Services\Log::setContainer($container);

        $controller = $this->makeController();
        $created = $controller->create($this->requestAs(true));
        $filename = urldecode(substr($this->locationOf($created), strlen('/admin/backup?success=created_')));

        $logs->expects($this->atLeastOnce())->method('record');

        $_POST = ['filename' => $filename];
        $response = $controller->delete($this->requestAs(true));
        $_POST = [];

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=deleted', $this->locationOf($response));

        \kintai\Core\Services\Log::reset();
    }

    public function testDescribeFlashParsesUpdateSummary(): void
    {
        $flash = $this->describeFlash(
            $this->makeController(),
            'updated_2.0.0_files-42_deleted-3_migrations-5_composer-ok'
        );

        $this->assertSame('success', $flash['type']);
        $this->assertStringContainsString('2.0.0', $flash['text']);
        $this->assertStringContainsString('42', $flash['text']);
        $this->assertStringContainsString('3', $flash['text']);
        $this->assertStringContainsString('5', $flash['text']);
    }
}
