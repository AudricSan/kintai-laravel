<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\WikiSyncService;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Le fetcher réseau est entièrement substitué (aucun accès réseau réel dans
 * ces tests) : on simule les réponses HTTP de raw.githubusercontent.com/wiki
 * à partir d'une petite table en mémoire indexée par URL.
 */
final class WikiSyncServiceTest extends TestCase
{
    private string $tmpWiki;

    protected function setUp(): void
    {
        $this->tmpWiki = sys_get_temp_dir() . '/kintai_wikisync_test_' . uniqid();
        putenv('GITHUB_UPDATE_REPO=test-owner/test-repo');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpWiki);
        putenv('GITHUB_UPDATE_REPO');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, array{status: int, body: ?string}> $responses URL => réponse simulée
     */
    private function service(array $responses): WikiSyncService
    {
        $fetcher = static function (string $url) use ($responses): array {
            if (!isset($responses[$url])) {
                return ['ok' => false, 'status' => 404, 'body' => null];
            }
            $r = $responses[$url];
            return [
                'ok'     => $r['status'] >= 200 && $r['status'] < 300,
                'status' => $r['status'],
                'body'   => $r['body'],
            ];
        };

        return new WikiSyncService($this->tmpWiki, $fetcher);
    }

    private const BASE_URL = 'https://raw.githubusercontent.com/wiki/test-owner/test-repo/';

    /**
     * Jeu de réponses minimal : sidebar avec une seule page ("Home"), disponible en anglais et en français (Accueil)
     * mais pas en japonais (ホーム, 404 : traduction manquante). Le wiki GitHub est à plat.
     *
     * @return array<string, array{status: int, body: ?string}>
     */
    private function minimalFixture(string $homeBodyEn = "# Home\n\n🌐 **English** · [Français](Accueil) · [日本語](ホーム)\n\nEnglish body."): array
    {
        return [
            self::BASE_URL . '_Sidebar.md'                    => ['status' => 200, 'body' => "**[Home](/test-owner/test-repo/wiki/Home)**\n"],
            self::BASE_URL . 'Home.md'                        => ['status' => 200, 'body' => $homeBodyEn],
            self::BASE_URL . 'Accueil.md'                     => ['status' => 200, 'body' => "# Accueil\n\nTexte français."],
            self::BASE_URL . rawurlencode('ホーム') . '.md'    => ['status' => 404, 'body' => null],
        ];
    }

    // -------------------------------------------------------------------------
    // Échec de récupération du _Sidebar.md
    // -------------------------------------------------------------------------

    public function testSyncFailsWhenSidebarUnreachable(): void
    {
        $service = $this->service([
            self::BASE_URL . '_Sidebar.md' => ['status' => 500, 'body' => null],
        ]);

        $result = $service->sync();

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('_Sidebar.md', $result['errors'][0]);
    }

    public function testSyncDoesNotCreateWikiDirWhenSidebarUnreachable(): void
    {
        $service = $this->service([
            self::BASE_URL . '_Sidebar.md' => ['status' => 500, 'body' => null],
        ]);

        $service->sync();

        $this->assertDirectoryDoesNotExist($this->tmpWiki);
    }

    // -------------------------------------------------------------------------
    // Premier clonage (dossier absent)
    // -------------------------------------------------------------------------

    public function testFreshSyncCreatesWikiDirectory(): void
    {
        $service = $this->service($this->minimalFixture());
        $service->sync();

        $this->assertDirectoryExists($this->tmpWiki);
    }

    public function testFreshSyncWritesSidebarAndAvailablePages(): void
    {
        $service = $this->service($this->minimalFixture());
        $service->sync();

        $this->assertFileExists($this->tmpWiki . '/_Sidebar.md');
        $this->assertFileExists($this->tmpWiki . '/Home.md');
        $this->assertFileExists($this->tmpWiki . '/Accueil.md');
        $this->assertSame("# Accueil\n\nTexte français.", file_get_contents($this->tmpWiki . '/Accueil.md'));
    }

    public function testFreshSyncDoesNotCreateFileFor404Translation(): void
    {
        $service = $this->service($this->minimalFixture());
        $service->sync();

        $this->assertFileDoesNotExist($this->tmpWiki . '/ホーム.md');
    }

    public function testFreshSyncReportsAddedCounts(): void
    {
        $service = $this->service($this->minimalFixture());
        $result = $service->sync();

        // _Sidebar.md + Home.md (en) + Accueil.md (fr) = 3 fichiers ajoutés ; ホーム.md est ignoré (404).
        $this->assertSame(3, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame(1, $result['skipped']);
        $this->assertTrue($result['ok']);
    }

    // -------------------------------------------------------------------------
    // Idempotence / mise à jour
    // -------------------------------------------------------------------------

    public function testSecondSyncWithUnchangedContentReportsUnchanged(): void
    {
        $service = $this->service($this->minimalFixture());
        $service->sync();

        $result = $service->sync();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(3, $result['unchanged']);
    }

    public function testSyncOverwritesChangedPageAndReportsUpdated(): void
    {
        $service = $this->service($this->minimalFixture());
        $service->sync();

        $updatedService = $this->service($this->minimalFixture(homeBodyEn: "# Home\n\n🌐 **English** · [Français](Accueil) · [日本語](ホーム)\n\nUpdated English body."));
        $result = $updatedService->sync();

        $this->assertSame(1, $result['updated']);
        $this->assertSame(2, $result['unchanged']); // _Sidebar.md + Accueil.md inchangés
        $this->assertStringContainsString('Updated English body.', (string) file_get_contents($this->tmpWiki . '/Home.md'));
    }

    // -------------------------------------------------------------------------
    // Erreurs partielles (une page échoue, les autres continuent)
    // -------------------------------------------------------------------------

    public function testSyncRecordsNon404ErrorsWithoutStoppingOtherPages(): void
    {
        $fixture = $this->minimalFixture();
        $fixture[self::BASE_URL . 'Accueil.md'] = ['status' => 503, 'body' => null];

        $service = $this->service($fixture);
        $result = $service->sync();

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Accueil.md', $result['errors'][0]);
        // La page anglaise, elle, a bien été écrite malgré l'échec de la page française.
        $this->assertFileExists($this->tmpWiki . '/Home.md');
    }

    // -------------------------------------------------------------------------
    // Plusieurs pages dans le sommaire
    // -------------------------------------------------------------------------

    public function testSyncFetchesEveryPageListedInSidebar(): void
    {
        $fixture = [
            self::BASE_URL . '_Sidebar.md' => [
                'status' => 200,
                'body'   => "**[Home](/test-owner/test-repo/wiki/Home)**\n\n**Getting Started**\n- [Installation](/test-owner/test-repo/wiki/Installation)\n",
            ],
            self::BASE_URL . 'Home.md'          => ['status' => 200, 'body' => "# Home\n\n🌐 **English** · [Français](Accueil) · [日本語](ホーム)"],
            self::BASE_URL . 'Installation.md'  => ['status' => 200, 'body' => "# Installation\n\n🌐 **English** · [Français](Installer-Kintai) · [日本語](インストール)"],
            self::BASE_URL . 'Accueil.md'       => ['status' => 200, 'body' => '# Accueil'],
            self::BASE_URL . 'Installer-Kintai.md' => ['status' => 200, 'body' => '# Installation FR'],
            self::BASE_URL . rawurlencode('ホーム') . '.md'       => ['status' => 200, 'body' => '# ホーム'],
            self::BASE_URL . rawurlencode('インストール') . '.md' => ['status' => 200, 'body' => '# インストール'],
        ];

        $service = $this->service($fixture);
        $result = $service->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(7, $result['added']); // _Sidebar.md + 2 pages x 3 langues
        $this->assertFileExists($this->tmpWiki . '/Installation.md');
        $this->assertFileExists($this->tmpWiki . '/Installer-Kintai.md');
        $this->assertFileExists($this->tmpWiki . '/インストール.md');
    }

    public function testSyncFetchesTranslatedPagesUnderTheirOwnFileName(): void
    {
        $en = "# Backups\n\n🌐 **English** · [Français](Sauvegardes) · [日本語](バックアップ)\n";
        $service = $this->service([
            self::BASE_URL . '_Sidebar.md'                  => ['status' => 200, 'body' => "**[Home](Home)**\n\n**Admin**\n- [Backups](Backups)\n"],
            self::BASE_URL . 'Home.md'                      => ['status' => 200, 'body' => "# Home\n"],
            self::BASE_URL . 'Backups.md'                   => ['status' => 200, 'body' => $en],
            self::BASE_URL . 'Sauvegardes.md'               => ['status' => 200, 'body' => "# Sauvegardes\n"],
            self::BASE_URL . rawurlencode('バックアップ') . '.md' => ['status' => 200, 'body' => "# バックアップ\n"],
        ]);

        $result = $service->sync();

        $this->assertTrue($result['ok']);
        $this->assertFileExists($this->tmpWiki . '/Sauvegardes.md');
        $this->assertFileExists($this->tmpWiki . '/バックアップ.md');
    }
}
