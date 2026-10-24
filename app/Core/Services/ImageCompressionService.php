<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Compresse les images envoyées par les stores pour rester sous une taille cible
 * (300 Ko par défaut) : baisse progressive de la qualité JPEG, puis réduction des
 * dimensions si la qualité minimale ne suffit pas. Les images à canal alpha (PNG
 * transparent, GIF) sont réencodées en PNG sans perte plutôt qu'en JPEG.
 */
final class ImageCompressionService
{
    public const DEFAULT_MAX_BYTES = 300 * 1024;

    private const MIN_QUALITY = 40;
    private const QUALITY_STEP = 10;
    private const MIN_DIMENSION = 480;
    private const RESIZE_FACTOR = 0.85;
    private const MAX_ATTEMPTS = 12;

    /**
     * Compresse $sourcePath et écrit le résultat vers "{$destPathWithoutExt}.{ext}"
     * (l'extension finale est choisie par le service selon le format retenu).
     * Retourne null si le fichier source n'est pas une image décodable par GD.
     *
     * @return array{path: string, extension: string, mime: string, size: int}|null
     */
    public function compress(string $sourcePath, string $destPathWithoutExt, int $maxBytes = self::DEFAULT_MAX_BYTES): ?array
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

        $current = $source;
        $quality = 85;
        $encodedPath = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $destPathWithoutExt . '.tmp' . $attempt;
            $this->encode($current, $candidate, $keepAlpha, $quality);

            if ($encodedPath !== null) {
                @unlink($encodedPath);
            }
            $encodedPath = $candidate;

            $size = filesize($encodedPath);
            if ($size !== false && $size <= $maxBytes) {
                break;
            }

            if (!$keepAlpha && $quality > self::MIN_QUALITY) {
                $quality -= self::QUALITY_STEP;
                continue;
            }

            $width  = imagesx($current);
            $height = imagesy($current);
            if (min($width, $height) <= self::MIN_DIMENSION) {
                break;
            }

            $resized = imagescale($current, (int) round($width * self::RESIZE_FACTOR), (int) round($height * self::RESIZE_FACTOR));
            if ($current !== $source) {
                imagedestroy($current);
            }
            $current = $resized;
            $quality = 85;
        }

        if ($current !== $source) {
            imagedestroy($current);
        }
        imagedestroy($source);

        $extension = $keepAlpha ? 'png' : 'jpg';
        $destPath = $destPathWithoutExt . '.' . $extension;
        rename($encodedPath, $destPath);

        return [
            'path'      => $destPath,
            'extension' => $extension,
            'mime'      => $keepAlpha ? 'image/png' : 'image/jpeg',
            'size'      => (int) filesize($destPath),
        ];
    }

    /**
     * Rotation manuelle d'une image déjà stockée (bouton "redresser" de la vue détail
     * d'un envoi), en écrasant le fichier en place. $degrees : sens horaire positif.
     */
    public function rotateInPlace(string $path, string $mime, int $degrees): bool
    {
        $image = $this->loadImage($path, $mime);
        if ($image === null) {
            return false;
        }

        // imagerotate() tourne dans le sens antihoraire : on inverse le signe pour
        // que l'appelant raisonne en sens horaire, plus intuitif pour des boutons ⟲/⟳.
        $rotated = imagerotate($image, -$degrees, 0);
        imagedestroy($image);
        if (!($rotated instanceof \GdImage)) {
            return false;
        }

        $keepAlpha = $mime === 'image/png' || $mime === 'image/gif';
        if ($keepAlpha) {
            imagesavealpha($rotated, true);
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($rotated, $path, 90),
            'image/png'  => imagepng($rotated, $path, 6),
            'image/gif'  => imagegif($rotated, $path),
            'image/webp' => function_exists('imagewebp') ? imagewebp($rotated, $path, 85) : false,
            default      => false,
        };
        imagedestroy($rotated);

        return $ok;
    }

    /**
     * Applique la rotation/symétrie indiquée par le tag EXIF Orientation de la photo
     * source, avant compression : les téléphones stockent souvent l'image telle que
     * capturée par le capteur (parfois de travers) avec ce tag pour indiquer comment
     * l'afficher, mais GD ignore ce tag et imagejpeg() ne le réécrit pas — sans cette
     * correction, la photo compressée serait de travers de façon permanente.
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
     * @param \GdImage $image
     */
    private function encode($image, string $path, bool $keepAlpha, int $quality): void
    {
        if ($keepAlpha) {
            imagesavealpha($image, true);
            imagepng($image, $path, 9);
            return;
        }

        imagejpeg($image, $path, $quality);
    }

    /**
     * Lit le champ "color type" de l'entête IHDR d'un PNG pour détecter un canal alpha
     * (types 4 = niveaux de gris + alpha, 6 = couleur vraie + alpha), sans dépendre de GD.
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
