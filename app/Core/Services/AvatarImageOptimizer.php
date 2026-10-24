<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Optimise une photo de profil pour un affichage en case carrée fixe (avatar).
 * Contrairement à ImageCompressionService (pensé pour des photos de reportage à
 * cadrage préservé et taille variable, avec une boucle qualité/dimension pour
 * viser un budget en Ko), l'avatar est toujours affiché rogné en carré par le
 * CSS (`object-fit: cover`, 72px maximum) : autant stocker directement ce carré
 * à une taille fixe plutôt qu'un rectangle dont une partie ne sera jamais montrée.
 */
final class AvatarImageOptimizer
{
    public const TARGET_SIZE = 256;

    private const JPEG_QUALITY = 82;

    /**
     * Rogne $sourcePath au centre en carré, le redimensionne à TARGET_SIZE (sans
     * jamais agrandir une image plus petite) et écrit le résultat vers
     * "{$destPathWithoutExt}.{ext}" (l'extension finale est choisie par le service
     * selon le format retenu). Retourne null si le fichier source n'est pas une
     * image décodable par GD.
     *
     * @return array{path: string, extension: string, mime: string, size: int}|null
     */
    public function optimize(string $sourcePath, string $destPathWithoutExt): ?array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        $mime = $info['mime'];
        $source = $this->loadImage($sourcePath, $mime);
        if ($source === null) {
            return null;
        }

        if ($mime === 'image/jpeg') {
            $source = $this->applyExifOrientation($source, $sourcePath);
        }

        $keepAlpha = ($mime === 'image/png' && $this->pngHasAlpha($sourcePath)) || $mime === 'image/gif';

        $square = $this->cropToSquare($source);
        imagedestroy($source);

        $side = imagesx($square);
        if ($side > self::TARGET_SIZE) {
            $resized = $this->resizeSquare($square, self::TARGET_SIZE);
            imagedestroy($square);
            $square = $resized;
        }

        $extension = $keepAlpha ? 'png' : 'jpg';
        $destPath = $destPathWithoutExt . '.' . $extension;
        $this->encode($square, $destPath, $keepAlpha);
        imagedestroy($square);

        return [
            'path'      => $destPath,
            'extension' => $extension,
            'mime'      => $keepAlpha ? 'image/png' : 'image/jpeg',
            'size'      => (int) filesize($destPath),
        ];
    }

    /**
     * Rogne au centre sur le plus petit côté (carré), en préservant le canal
     * alpha exact du pixel source (imagealphablending désactivé : sans ça, GD
     * mélangerait les pixels semi-transparents avec le fond au lieu de les copier
     * tels quels).
     *
     * @param \GdImage $image
     * @return \GdImage
     */
    private function cropToSquare($image)
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $side   = min($width, $height);
        $srcX   = (int) round(($width - $side) / 2);
        $srcY   = (int) round(($height - $side) / 2);

        $square = imagecreatetruecolor($side, $side);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
        imagefill($square, 0, 0, $transparent);

        imagecopy($square, $image, 0, 0, $srcX, $srcY, $side, $side);

        return $square;
    }

    /**
     * @param \GdImage $image
     * @return \GdImage
     */
    private function resizeSquare($image, int $newSize)
    {
        $resized = imagecreatetruecolor($newSize, $newSize);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newSize, $newSize, imagesx($image), imagesy($image));

        return $resized;
    }

    /**
     * @param \GdImage $image
     */
    private function encode($image, string $path, bool $keepAlpha): void
    {
        if ($keepAlpha) {
            imagesavealpha($image, true);
            imagepng($image, $path, 9);
            return;
        }

        imagejpeg($image, $path, self::JPEG_QUALITY);
    }

    /**
     * Applique la rotation/symétrie indiquée par le tag EXIF Orientation de la
     * photo source, avant recadrage : les téléphones stockent souvent l'image
     * telle que capturée par le capteur (parfois de travers) avec ce tag, mais GD
     * l'ignore à la lecture.
     *
     * @param \GdImage $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, string $sourcePath)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($sourcePath);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->rotate($image, -90),
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    /** @param \GdImage $image @return \GdImage */
    private function flip($image, int $mode)
    {
        imageflip($image, $mode);
        return $image;
    }

    /** @param \GdImage $image @return \GdImage */
    private function rotate($image, float $angle)
    {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }

    /**
     * @return \GdImage|null
     */
    private function loadImage(string $path, string $mime)
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }

    /**
     * Lit le champ "color type" de l'entête IHDR d'un PNG pour détecter un canal
     * alpha (types 4 = niveaux de gris + alpha, 6 = couleur vraie + alpha), sans
     * dépendre de GD.
     */
    private function pngHasAlpha(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        fseek($handle, 25);
        $byte = fread($handle, 1);
        fclose($handle);

        return $byte !== false && $byte !== '' && in_array(ord($byte), [4, 6], true);
    }
}
