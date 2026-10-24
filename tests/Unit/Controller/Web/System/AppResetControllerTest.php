<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web\System;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Auth\AuthService;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AppResetService;
use kintai\Core\Services\BackupService;
use kintai\UI\Controller\Web\System\AppResetController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * BackupService/AppResetService/AuthService sont `final` — non doublables par
 * PHPUnit (voir BackupControllerTest, même contrainte) — on utilise donc de
 * vraies instances adossées à un dossier temporaire et une base migrée réelle.
 */
final class AppResetControllerTest extends TestCase
{
    private string $tmpDir;
    private BackupService $backup;
    private AppResetService $reset;
    private AuthService $auth;
    private AppResetController $controller;

    protected function setUp(): void
    {
        // HasBaseUrl::base() dérive le préfixe de $_SERVER['SCRIPT_NAME'], qui en CLI
        // PHPUnit pointe vers vendor/bin/phpunit — on le fixe pour simuler un vrai
        // index.php à la racine (base() renvoie alors '', comme en prod sans sous-dossier).
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->tmpDir = sys_get_temp_dir() . '/kintai_resetctrl_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/storage/backups', 0775, true);
        mkdir($this->tmpDir . '/storage/uploads', 0775, true);
        file_put_contents($this->tmpDir . '/storage/installed.lock', 'x');
        putenv('KINTAI_STORAGE_PATH=' . $this->tmpDir . '/storage');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $this->setPrivate($runner, 'capsule', $capsule);
        $this->setPrivate($runner, 'migrationsPath', dirname(__DIR__, 5) . '/database/migrations/php');
        $runner->run();
        $capsule->table('users')->insert([
            'first_name' => 'Owner', 'last_name' => 'Test', 'display_name' => 'Owner Test',
            'email' => 'owner@test.com', 'password_hash' => 'x', 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->backup = new BackupService($capsule);
        $this->setPrivate($this->backup, 'backupDir', $this->tmpDir . '/storage/backups');

        $this->reset = new AppResetService($capsule, $runner);

        $this->auth = new AuthService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $view = new ViewRenderer(sys_get_temp_dir());
        $this->ensureViewFile(sys_get_temp_dir(), 'system.reset-confirm');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $this->controller = new AppResetController($view, $this->backup, $this->reset, $this->auth);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        putenv('KINTAI_STORAGE_PATH');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    private function ensureViewFile(string $dir, string $view): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        touch($file);
    }

    private function requestAs(bool $isOwner): Request
    {
        $request = new Request();
        $request->setAttribute('auth_user', ['id' => 1, 'email' => 'owner@test.com', 'is_admin' => $isOwner]);
        return $request;
    }

    private function locationOf(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');
        $ref->setAccessible(true);
        return $ref->getValue($response)['Location'] ?? '';
    }

    // Le rejet non-Owner est désormais couvert par OwnerOnlyMiddlewareTest
    // (route-level, RBAC-V2) — le contrôleur ne se garde plus lui-même.

    public function testPrepareRejectsInvalidMode(): void
    {
        $_POST = ['mode' => 'nuke'];

        $response = $this->controller->prepare($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('error=invalid_reset_mode', $this->locationOf($response));
        $this->assertCount(0, glob($this->tmpDir . '/storage/backups/*.zip') ?: [], 'aucune sauvegarde ne doit être créée pour un mode invalide');
    }

    public function testPrepareCreatesBackupAndRendersConfirmation(): void
    {
        $_POST = ['mode' => 'data', 'keep' => ['stores', 'not_a_real_category']];

        $response = $this->controller->prepare($this->requestAs(true));

        $this->assertSame(200, $response->status());
        $this->assertCount(1, glob($this->tmpDir . '/storage/backups/*.zip') ?: [], 'la sauvegarde doit avoir été créée avant la page de confirmation');
    }

    // Le rejet non-Owner est désormais couvert par OwnerOnlyMiddlewareTest
    // (route-level, RBAC-V2) — le contrôleur ne se garde plus lui-même.

    public function testExecuteRejectsMissingBackupFile(): void
    {
        $_POST = ['mode' => 'data', 'backup_filename' => 'does_not_exist.zip'];

        $response = $this->controller->execute($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('error=reset_backup_missing', $this->locationOf($response));
        $this->assertSame(1, Capsule::table('users')->count(), 'rien ne doit être vidé si la sauvegarde est manquante');
    }

    public function testExecuteRunsDataResetKeepingUserAndRedirectsToBackupPage(): void
    {
        $backup = $this->backup->create('test');
        $_POST = ['mode' => 'data', 'keep' => [], 'backup_filename' => $backup['filename']];

        $response = $this->controller->execute($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('success=reset_data', $this->locationOf($response));
        $this->assertSame(1, Capsule::table('users')->count(), 'users doit être conservé en mode données');
        $this->assertFileExists($this->tmpDir . '/storage/installed.lock');
    }

    public function testExecuteRunsFactoryResetLogsOutAndRedirectsHome(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $backup = $this->backup->create('test');
        $_POST = ['mode' => 'factory', 'backup_filename' => $backup['filename']];

        $response = $this->controller->execute($this->requestAs(true));

        $this->assertSame(302, $response->status());
        $this->assertSame('/', $this->locationOf($response));
        $this->assertSame(0, Capsule::table('users')->count(), 'users doit être vidé en mode usine');
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION, 'la session doit être détruite après un reset usine');
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/installed.lock');
    }
}
