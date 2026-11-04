<?php

namespace Tests\Unit\Support;

use App\Support\AvatarProcessor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AvatarProcessorTest extends TestCase
{
    private function png(int $width, int $height, bool $transparent): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 200, 30, 30, 0));

        if ($transparent) {
            imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 120, 200));

        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function info(string $contents): array
    {
        $info = getimagesizefromstring($contents);
        $this->assertNotFalse($info);

        return [$info[0], $info[1], $info[2]];
    }

    public function test_transparent_png_stays_png_and_is_cropped_square_and_reduced(): void
    {
        $result = (new AvatarProcessor)->process($this->png(600, 400, true));

        $this->assertSame('png', $result['extension']);
        $this->assertSame([256, 256, IMAGETYPE_PNG], $this->info($result['contents']));
    }

    public function test_opaque_png_becomes_jpeg(): void
    {
        $result = (new AvatarProcessor)->process($this->png(300, 300, false));

        $this->assertSame('jpg', $result['extension']);
        $this->assertSame([256, 256, IMAGETYPE_JPEG], $this->info($result['contents']));
    }

    public function test_jpeg_stays_jpeg(): void
    {
        $result = (new AvatarProcessor)->process($this->jpeg(800, 1200));

        $this->assertSame('jpg', $result['extension']);
        $this->assertSame([256, 256, IMAGETYPE_JPEG], $this->info($result['contents']));
    }

    public function test_small_images_are_cropped_but_never_enlarged(): void
    {
        $result = (new AvatarProcessor)->process($this->jpeg(100, 60));

        $this->assertSame([60, 60, IMAGETYPE_JPEG], $this->info($result['contents']));
    }

    public function test_exif_orientation_rotates_jpeg(): void
    {
        // 200x100, moitié gauche rouge et moitié droite bleue, marquée « à tourner de 90° dans le sens horaire ».
        $image = imagecreatetruecolor(200, 100);
        imagefilledrectangle($image, 0, 0, 99, 99, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 100, 0, 199, 99, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = (string) ob_get_clean();
        $exif = "\xFF\xE1\x00\x22Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
        $tagged = substr($jpeg, 0, 2).$exif.substr($jpeg, 2);

        $this->assertSame(6, exif_read_data('data://image/jpeg;base64,'.base64_encode($tagged))['Orientation'] ?? null);

        $result = (new AvatarProcessor)->process($tagged);
        $output = imagecreatefromstring($result['contents']);
        $this->assertNotFalse($output);

        // Après rotation, le rouge est en haut et le bleu en bas ; sans rotation, ces deux points seraient de la même couleur.
        $top = imagecolorat($output, 50, 5);
        $bottom = imagecolorat($output, 50, 95);
        $this->assertGreaterThan(150, ($top >> 16) & 0xFF);
        $this->assertGreaterThan(150, $bottom & 0xFF);
    }

    public function test_undecodable_content_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AvatarProcessor)->process('definitely not an image');
    }
}
