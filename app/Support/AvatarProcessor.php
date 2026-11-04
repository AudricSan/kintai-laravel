<?php

namespace App\Support;

use GdImage;
use InvalidArgumentException;

/**
 * Prépare un avatar avec GD : rotation EXIF, recadrage carré centré, réduction à 256 px au plus (jamais d'agrandissement).
 * Sortie en PNG si l'image a de la transparence, sinon en JPEG (qualité 82).
 */
class AvatarProcessor
{
    public const MAX_SIZE = 256;

    /** Limite de pixels décodés, pour ne pas épuiser la mémoire avec une image piégée. */
    private const MAX_PIXELS = 40_000_000;

    /**
     * @return array{contents: string, extension: string}
     *
     * @throws InvalidArgumentException si le contenu n'est pas une image décodable
     */
    public function process(string $contents): array
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('The file is not a readable image.');
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('The file is not a readable image.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->orient($source, $this->exifOrientation($contents));
        }

        imagepalettetotruecolor($source);

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = min($side, self::MAX_SIZE);

        $square = imagecreatetruecolor($target, $target);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, (int) imagecolorallocatealpha($square, 0, 0, 0, 127));

        imagecopyresampled(
            $square, $source, 0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $target, $target, $side, $side,
        );

        return $this->hasTransparency($square)
            ? ['contents' => $this->encode(fn () => imagepng($square, null, 6)), 'extension' => 'png']
            : ['contents' => $this->encode(fn () => imagejpeg($square, null, 82)), 'extension' => 'jpg'];
    }

    private function exifOrientation(string $contents): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));

        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        return imagerotate($image, $angle, 0) ?: $image;
    }

    private function hasTransparency(GdImage $image): bool
    {
        $size = imagesx($image);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  callable(): mixed  $write  écrit l'image sur la sortie standard
     */
    private function encode(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
