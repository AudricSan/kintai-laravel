<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Services\ImageCompressionService;
use PHPUnit\Framework\TestCase;

final class ImageCompressionServiceTest extends TestCase
{
    private ImageCompressionService $service;
    private string $workDir;

    protected function setUp(): void
    {
        $this->service = new ImageCompressionService();
        $this->workDir = sys_get_temp_dir() . '/kintai-image-compression-' . uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    public function testCompressesLargeOpaqueImageBelowTargetSizeAsJpeg(): void
    {
        $source = $this->workDir . '/source.png';
        $this->writeNoisyOpaquePng($source, 1200, 900);
        $this->assertGreaterThan(ImageCompressionService::DEFAULT_MAX_BYTES, filesize($source));

        $result = $this->service->compress($source, $this->workDir . '/out', ImageCompressionService::DEFAULT_MAX_BYTES);

        $this->assertNotNull($result);
        $this->assertSame('jpg', $result['extension']);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertLessThanOrEqual(ImageCompressionService::DEFAULT_MAX_BYTES, $result['size']);
        $this->assertFileExists($result['path']);
    }

    public function testKeepsPngWithAlphaChannelForTransparentImages(): void
    {
        $source = $this->workDir . '/transparent.png';
        $this->writeTransparentPng($source, 300, 300);

        $result = $this->service->compress($source, $this->workDir . '/out', ImageCompressionService::DEFAULT_MAX_BYTES);

        $this->assertNotNull($result);
        $this->assertSame('png', $result['extension']);
        $this->assertSame('image/png', $result['mime']);
    }

    public function testShrinksDimensionsWhenMinimumQualityStillExceedsTinyBudget(): void
    {
        $source = $this->workDir . '/source.png';
        $this->writeNoisyOpaquePng($source, 1200, 900);

        // Budget volontairement irréaliste pour forcer plusieurs passes de redimensionnement.
        $result = $this->service->compress($source, $this->workDir . '/out', 8 * 1024);

        $this->assertNotNull($result);
        [$width, $height] = getimagesize($result['path']);
        $this->assertLessThan(1200, $width);
        $this->assertLessThan(900, $height);
    }

    public function testReturnsNullForNonImageFile(): void
    {
        $source = $this->workDir . '/not-an-image.jpg';
        file_put_contents($source, 'this is definitely not image bytes');

        $result = $this->service->compress($source, $this->workDir . '/out');

        $this->assertNull($result);
    }

    /**
     * Un téléphone stocke souvent la photo telle que capturée par le capteur
     * (potentiellement de travers) avec un tag EXIF Orientation indiquant comment
     * l'afficher. GD ignore ce tag à la lecture et ne le réécrit jamais à
     * l'encodage : sans correction préalable, la photo compressée reste de travers
     * de façon permanente (l'info est perdue). Orientation 6 = pivoter 90° horaire.
     */
    public function testAppliesExifOrientationBeforeCompressing(): void
    {
        $source = $this->workDir . '/oriented.jpg';
        $this->writeJpegWithExifOrientation($source, 100, 50, 6);

        $result = $this->service->compress($source, $this->workDir . '/out', ImageCompressionService::DEFAULT_MAX_BYTES);

        $this->assertNotNull($result);
        [$width, $height] = getimagesize($result['path']);
        $this->assertSame(50, $width);
        $this->assertSame(100, $height);
        $this->assertMarkerNear($result['path'], $width - 5, 5);
    }

    public function testRotateInPlaceRotatesClockwiseAndOverwritesTheFile(): void
    {
        $path = $this->workDir . '/photo.jpg';
        $this->writeMarkedJpeg($path, 100, 50);

        $ok = $this->service->rotateInPlace($path, 'image/jpeg', 90);

        $this->assertTrue($ok);
        [$width, $height] = getimagesize($path);
        $this->assertSame(50, $width);
        $this->assertSame(100, $height);
        $this->assertMarkerNear($path, $width - 5, 5);
    }

    public function testRotateInPlaceReturnsFalseForNonImageFile(): void
    {
        $path = $this->workDir . '/not-an-image.jpg';
        file_put_contents($path, 'nope');

        $this->assertFalse($this->service->rotateInPlace($path, 'image/jpeg', 90));
    }

    private function writeNoisyOpaquePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 3) {
            for ($y = 0; $y < $height; $y += 3) {
                $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 2, $y + 2, $color);
            }
        }
        imagepng($image, $path, 0);
        imagedestroy($image);
    }

    private function writeTransparentPng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $opaque = imagecolorallocatealpha($image, 200, 50, 50, 0);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), $opaque);
        imagepng($image, $path, 0);
        imagedestroy($image);
    }

    /** JPEG avec un marqueur rouge dans le coin haut-gauche, pour vérifier une rotation par sa nouvelle position. */
    private function writeMarkedJpeg(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bg);
        $marker = imagecolorallocate($image, 255, 0, 0);
        imagefilledrectangle($image, 0, 0, 9, 9, $marker);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    /**
     * JPEG marqué (voir writeMarkedJpeg) avec un segment APP1/EXIF minimal inséré
     * juste après le SOI, portant uniquement le tag Orientation — GD ne sachant pas
     * écrire l'EXIF, ce segment est construit à la main (TIFF little-endian, une
     * seule entrée d'IFD).
     */
    private function writeJpegWithExifOrientation(string $path, int $width, int $height, int $orientation): void
    {
        $raw = $this->workDir . '/exif-src-raw.jpg';
        $this->writeMarkedJpeg($raw, $width, $height);

        $bytes = (string) file_get_contents($raw);
        $soi   = substr($bytes, 0, 2);
        $rest  = substr($bytes, 2);

        file_put_contents($path, $soi . $this->buildExifOrientationSegment($orientation) . $rest);
        unlink($raw);
    }

    private function buildExifOrientationSegment(int $orientation): string
    {
        $tiffHeader = 'II' . pack('v', 42) . pack('V', 8);
        $entry      = pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . "\x00\x00";
        $ifd        = pack('v', 1) . $entry . pack('V', 0);
        $exif       = "Exif\x00\x00" . $tiffHeader . $ifd;

        return "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
    }

    /** Vérifie que le pixel à ($x, $y) est bien rouge (voir writeMarkedJpeg). */
    private function assertMarkerNear(string $jpegPath, int $x, int $y): void
    {
        $image = imagecreatefromjpeg($jpegPath);
        $this->assertNotFalse($image);
        $color = imagecolorat($image, $x, $y);
        imagedestroy($image);

        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;

        $this->assertGreaterThan(150, $r, "Pixel ($x,$y) devrait être rouge (marqueur), R=$r G=$g B=$b");
        $this->assertLessThan(100, $g, "Pixel ($x,$y) devrait être rouge (marqueur), R=$r G=$g B=$b");
        $this->assertLessThan(100, $b, "Pixel ($x,$y) devrait être rouge (marqueur), R=$r G=$g B=$b");
    }
}
