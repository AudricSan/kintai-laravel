<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Services\AvatarImageOptimizer;
use PHPUnit\Framework\TestCase;

final class AvatarImageOptimizerTest extends TestCase
{
    private AvatarImageOptimizer $service;
    private string $workDir;

    protected function setUp(): void
    {
        $this->service = new AvatarImageOptimizer();
        $this->workDir = sys_get_temp_dir() . '/kintai-avatar-optimizer-' . uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    public function testCropsRectangularImageToSquareAtTargetSize(): void
    {
        $source = $this->workDir . '/source.png';
        $this->writeOpaquePng($source, 1200, 900);

        $result = $this->service->optimize($source, $this->workDir . '/out');

        $this->assertNotNull($result);
        $this->assertSame('jpg', $result['extension']);
        $this->assertSame('image/jpeg', $result['mime']);
        [$width, $height] = getimagesize($result['path']);
        $this->assertSame(AvatarImageOptimizer::TARGET_SIZE, $width);
        $this->assertSame(AvatarImageOptimizer::TARGET_SIZE, $height);
    }

    public function testDoesNotUpscaleAnImageSmallerThanTargetSize(): void
    {
        $source = $this->workDir . '/small.png';
        $this->writeOpaquePng($source, 120, 90);

        $result = $this->service->optimize($source, $this->workDir . '/out');

        $this->assertNotNull($result);
        [$width, $height] = getimagesize($result['path']);
        // Rogné en carré sur le plus petit côté (90), jamais agrandi vers 256.
        $this->assertSame(90, $width);
        $this->assertSame(90, $height);
    }

    public function testKeepsPngWithAlphaChannelForTransparentImages(): void
    {
        $source = $this->workDir . '/transparent.png';
        $this->writeTransparentPng($source, 300, 300);

        $result = $this->service->optimize($source, $this->workDir . '/out');

        $this->assertNotNull($result);
        $this->assertSame('png', $result['extension']);
        $this->assertSame('image/png', $result['mime']);
        [$width, $height] = getimagesize($result['path']);
        $this->assertSame(AvatarImageOptimizer::TARGET_SIZE, $width);
        $this->assertSame(AvatarImageOptimizer::TARGET_SIZE, $height);
    }

    public function testReturnsNullForNonImageFile(): void
    {
        $source = $this->workDir . '/not-an-image.jpg';
        file_put_contents($source, 'this is definitely not image bytes');

        $result = $this->service->optimize($source, $this->workDir . '/out');

        $this->assertNull($result);
    }

    /**
     * Orientation 6 = pivoter 90° horaire : sans correction, l'avatar recadré
     * resterait de travers de façon permanente.
     */
    public function testAppliesExifOrientationBeforeCropping(): void
    {
        $source = $this->workDir . '/oriented.jpg';
        $this->writeJpegWithExifOrientation($source, 100, 50, 6);

        $result = $this->service->optimize($source, $this->workDir . '/out');

        $this->assertNotNull($result);
        // Image capteur 100x50 pivotée 90° -> 50x100 avant recadrage carré (côté 50).
        [$width, $height] = getimagesize($result['path']);
        $this->assertSame(50, $width);
        $this->assertSame(50, $height);
    }

    private function writeOpaquePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 80, 120, 200);
        imagefill($image, 0, 0, $bg);
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

    /** JPEG avec un segment APP1/EXIF minimal portant uniquement le tag Orientation. */
    private function writeJpegWithExifOrientation(string $path, int $width, int $height, int $orientation): void
    {
        $raw = $this->workDir . '/exif-src-raw.jpg';
        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bg);
        imagejpeg($image, $raw, 90);
        imagedestroy($image);

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
}
