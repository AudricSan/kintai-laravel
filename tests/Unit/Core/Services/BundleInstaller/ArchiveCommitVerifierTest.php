<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services\BundleInstaller;

use kintai\Core\Services\BundleInstaller\ArchiveCommitVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Un zipball GitHub porte le commit de son tag : sha complet dans le commentaire du ZIP, sha abrégé dans
 * le nom du dossier racine. Ces tests fabriquent des archives à ce format (voir « Getting listed in a
 * registry » dans wiki : Creating-a-Bundle) et vérifient que seule l'archive du commit épinglé passe.
 */
final class ArchiveCommitVerifierTest extends TestCase
{
    private const PINNED = '7f99e084014e8258003a8b2a8fd4d8a188cfa5c3';
    private const OTHER  = '0123456789abcdef0123456789abcdef01234567';

    /** @var string[] */
    private array $zips = [];

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
    }

    /** @param string|null $comment commentaire du ZIP (null = aucun) */
    private function zip(string $rootFolder, ?string $comment): string
    {
        $path = sys_get_temp_dir() . '/kintai-verify-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString("{$rootFolder}/bundle.json", '{}');
        if ($comment !== null) {
            $zip->setArchiveComment($comment);
        }
        $zip->close();
        $this->zips[] = $path;

        return $path;
    }

    public function testArchiveOfThePinnedCommitPasses(): void
    {
        $zip = $this->zip('AudricSan-kintai-bundle-x-7f99e08', self::PINNED);

        $this->assertNull(ArchiveCommitVerifier::verify($zip, self::PINNED));
    }

    public function testArchiveOfAnotherCommitIsRefused(): void
    {
        // Tag déplacé : GitHub génère l'archive du NOUVEAU commit, qui ne correspond plus à l'épinglage.
        $zip = $this->zip('AudricSan-kintai-bundle-x-0123456', self::OTHER);

        $error = ArchiveCommitVerifier::verify($zip, self::PINNED);

        $this->assertNotNull($error);
        $this->assertStringContainsString('installation refusée', $error);
        $this->assertStringContainsString('0123456789ab', $error, "L'erreur doit citer le commit reçu.");
        $this->assertStringContainsString('7f99e084014e', $error, "L'erreur doit citer le commit épinglé.");
    }

    public function testCommentWinsOverAMisleadingRootFolderName(): void
    {
        // Le nom du dossier est celui du bon commit, mais le commentaire (fiable) dit autre chose.
        $zip = $this->zip('AudricSan-kintai-bundle-x-7f99e08', self::OTHER);

        $this->assertNotNull(ArchiveCommitVerifier::verify($zip, self::PINNED));
    }

    public function testComparisonIsCaseInsensitive(): void
    {
        $zip = $this->zip('AudricSan-kintai-bundle-x-7f99e08', strtoupper(self::PINNED));

        $this->assertNull(ArchiveCommitVerifier::verify($zip, self::PINNED));
    }

    public function testWithoutCommentFallsBackToTheRootFolderShortSha(): void
    {
        $zip = $this->zip('AudricSan-kintai-bundle-x-7f99e08', null);

        $this->assertNull(ArchiveCommitVerifier::verify($zip, self::PINNED));
    }

    public function testWithoutCommentAWrongRootFolderShaIsRefused(): void
    {
        $zip = $this->zip('AudricSan-kintai-bundle-x-0123456', null);

        $this->assertNotNull(ArchiveCommitVerifier::verify($zip, self::PINNED));
    }

    public function testNoReadableCommitAnywhereIsRefused(): void
    {
        // Ni commentaire exploitable ni sha dans le nom du dossier : on ne peut pas prouver que l'archive
        // est la bonne, donc on refuse plutôt que de laisser passer.
        $zip = $this->zip('some-folder', 'not a commit');

        $error = ArchiveCommitVerifier::verify($zip, self::PINNED);

        $this->assertNotNull($error);
        $this->assertStringContainsString('Impossible de vérifier', $error);
    }

    public function testShortShaTooShortInTheFolderNameIsNotTrusted(): void
    {
        // 6 caractères hexadécimaux : trop peu pour identifier un commit.
        $zip = $this->zip('AudricSan-kintai-bundle-x-7f99e0', null);

        $this->assertNotNull(ArchiveCommitVerifier::verify($zip, self::PINNED));
    }

    public function testAMalformedPinnedCommitIsRefusedRatherThanCompared(): void
    {
        $zip = $this->zip('AudricSan-kintai-bundle-x-7f99e08', self::PINNED);

        $this->assertNotNull(ArchiveCommitVerifier::verify($zip, 'not-a-sha'));
        $this->assertNotNull(ArchiveCommitVerifier::verify($zip, ''));
    }

    public function testAnUnreadableArchiveIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/kintai-not-a-zip-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, 'this is not a zip');
        $this->zips[] = $path;

        $this->assertNotNull(ArchiveCommitVerifier::verify($path, self::PINNED));
    }
}
