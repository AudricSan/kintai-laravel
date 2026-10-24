<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Cron;

use PHPUnit\Framework\TestCase;
use kintai\Core\Cron\BackupJob;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\BackupService;

final class BackupJobTest extends TestCase
{
    private BackupJob $job;
    private string $tmpDir;

    protected function setUp(): void
    {
        $logger = new AuditLogger();

        // Évite de passer par le vrai DatabaseAppSettingsRepository (Eloquent),
        // qui nécessite une connexion DB non disponible en test unitaire.
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn([]);
        $settings = new AppSettingsService($repo);

        // Isole les fichiers de backup générés dans un dossier temporaire,
        // pour ne pas polluer le vrai storage/backups/ de l'application.
        $this->tmpDir = sys_get_temp_dir() . '/kintai_backupjob_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        $backupService = new BackupService(null);
        $ref = new \ReflectionProperty(BackupService::class, 'backupDir');
        $ref->setAccessible(true);
        $ref->setValue($backupService, $this->tmpDir);

        $this->job = new BackupJob($logger, $settings, $backupService);
    }

    // -------------------------------------------------------------------------
    // getName()
    // -------------------------------------------------------------------------

    public function testGetNameReturnsBackup(): void
    {
        $this->assertSame('backup', $this->job->getName());
    }

    // -------------------------------------------------------------------------
    // run()
    // -------------------------------------------------------------------------

    public function testRunReturnsOkOnSuccess(): void
    {
        $response = $this->job->run($this->makeRequest());
        $data     = json_decode($response->body(), true);

        $this->assertSame(200, $response->status());
        $this->assertTrue($data['ok']);
        $this->assertStringContainsString('backup_', $data['filename']);
        $this->assertIsInt($data['size']);
    }

    public function testRunWithCustomNote(): void
    {
        $response = $this->job->run($this->makeRequest(['note' => 'Avant mise à jour']));
        $data     = json_decode($response->body(), true);

        $this->assertTrue($data['ok']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(array $query = []): Request
    {
        $queryStr = $query ? '?' . http_build_query($query) : '';
        $_SERVER  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/cron/backup$queryStr", 'SCRIPT_NAME' => '/index.php'];
        $_GET     = $query;
        $_POST    = $_COOKIE = $_FILES = [];
        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
        }
    }

    public function testOptionalBackupServiceDefaultsWhenOmitted(): void
    {
        // Le service de sauvegarde facultatif a une valeur par défaut : « ??= » sur une propriété en lecture seule levait une Error.
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn([]);
        $job = new BackupJob(new AuditLogger(), new AppSettingsService($repo));

        $this->assertSame('backup', $job->getName());
    }
}
