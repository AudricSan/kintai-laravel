<?php

namespace Kintai\Bundles\StorePhotos;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Fichiers des photos : traitement des images envoyées et stockage privé.
 *
 * Une image n'est jamais conservée telle quelle. Le type est reconnu par le contenu (jamais par le nom ni le type
 * déclaré par le navigateur) parmi JPEG, PNG, GIF et WebP, la taille décodée est bornée, puis l'image est décodée par GD
 * et ré-encodée : métadonnées (EXIF, GPS, commentaires) et données ajoutées après l'image disparaissent, un fichier
 * piégé (polyglotte, script dans un commentaire) devient une image saine ou est refusé. La sortie est un JPEG (qualité
 * 82), ou un PNG si l'image a de la transparence, réduite à 2000 px de côté au plus, après correction de l'orientation
 * EXIF. Le nom de stockage est généré (32 hexadécimaux), jamais celui du client.
 *
 * Les fichiers vivent à plat dans `store-photos/` du disque privé `local`, inaccessible au web : ils ne se lisent que
 * par la route contrôlée du bundle.
 */
class PhotoStorage
{
    public const DIRECTORY = 'store-photos';

    /** Taille maximale d'un fichier envoyé. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Plus grand côté conservé (jamais d'agrandissement). */
    public const MAX_SIDE = 2000;

    /** Pixels décodés au plus, pour ne pas épuiser la mémoire avec une image piégée. */
    public const MAX_PIXELS = 40_000_000;

    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(?:jpg|png)$/';

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * Traite et enregistre une image.
     *
     * @return array{path: string, mime: string, size: int, width: int, height: int}
     *
     * @throws InvalidArgumentException Fichier trop gros, d'un autre type qu'une image, ou illisible.
     */
    public function store(string $contents): array
    {
        $processed = $this->process($contents);
        $name = bin2hex(random_bytes(16)).'.'.$processed['extension'];

        if (! $this->disk()->put(self::DIRECTORY.'/'.$name, $processed['contents'])) {
            throw new InvalidArgumentException('The photo could not be saved.');
        }

        return [
            'path' => $name, 'mime' => $processed['mime'], 'size' => strlen($processed['contents']),
            'width' => $processed['width'], 'height' => $processed['height'],
        ];
    }

    /**
     * Contrôle et ré-encode une image, sans l'enregistrer.
     *
     * @return array{contents: string, extension: string, mime: string, width: int, height: int}
     *
     * @throws InvalidArgumentException
     */
    public function process(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is empty or too large.');
        }

        $info = @getimagesizefromstring($contents);

        if ($info === false || ! in_array($info[2], self::TYPES, true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('The file is not a supported image.');
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('The file is not a readable image.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->orient($source, $this->exifOrientation($contents));
        }

        imagepalettetotruecolor($source);
        $source = $this->shrink($source);

        // Un JPEG n'a pas de transparence : inutile de parcourir ses pixels.
        return $this->encode($source, $info[2] !== IMAGETYPE_JPEG);
    }

    /**
     * Tourne la photo enregistrée de 90 degrés (positif = sens inverse des aiguilles d'une montre, comme GD) et la réécrit
     * sous le même nom.
     *
     * @return array{size: int, width: int, height: int}
     *
     * @throws InvalidArgumentException
     */
    public function rotate(string $name, int $degrees): array
    {
        $contents = $this->contents($name);
        $source = $contents === null ? false : @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('The photo cannot be read.');
        }

        imagepalettetotruecolor($source);
        imagesavealpha($source, true);
        $rotated = imagerotate($source, $degrees, (int) imagecolorallocatealpha($source, 0, 0, 0, 127));

        if (! $rotated instanceof GdImage) {
            throw new InvalidArgumentException('The photo cannot be rotated.');
        }

        imagesavealpha($rotated, true);
        $png = str_ends_with($name, '.png');
        $bytes = $png ? $this->write(fn () => imagepng($rotated, null, 6)) : $this->write(fn () => imagejpeg($rotated, null, 82));
        $this->disk()->put(self::DIRECTORY.'/'.$name, $bytes);

        return ['size' => strlen($bytes), 'width' => imagesx($rotated), 'height' => imagesy($rotated)];
    }

    /**
     * Contenu d'une photo enregistrée ; null si le nom n'est pas un nom généré ou si le fichier n'existe pas.
     */
    public function contents(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || ! $this->disk()->exists(self::DIRECTORY.'/'.$name)) {
            return null;
        }

        return $this->disk()->get(self::DIRECTORY.'/'.$name);
    }

    /**
     * Chemin relatif au disque d'une photo enregistrée, ou null (nom invalide, fichier absent).
     */
    public function relativePath(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || ! $this->disk()->exists(self::DIRECTORY.'/'.$name)) {
            return null;
        }

        return self::DIRECTORY.'/'.$name;
    }

    /**
     * Date de dernière modification (sert de version dans l'adresse, pour renouveler le cache après une rotation).
     */
    public function version(string $name): int
    {
        $path = $this->relativePath($name);

        return $path === null ? 0 : $this->disk()->lastModified($path);
    }

    public function delete(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) === 1) {
            $this->disk()->delete(self::DIRECTORY.'/'.$name);
        }
    }

    /**
     * Noms des fichiers du dossier des photos.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->disk()->files(self::DIRECTORY) as $file) {
            $name = basename($file);

            if (preg_match(self::NAME_PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function lastModified(string $name): int
    {
        return $this->disk()->lastModified(self::DIRECTORY.'/'.$name);
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
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

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function shrink(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = max($width, $height);

        if ($side <= self::MAX_SIDE) {
            return $image;
        }

        $ratio = self::MAX_SIDE / $side;
        $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /**
     * @return array{contents: string, extension: string, mime: string, width: int, height: int}
     */
    private function encode(GdImage $image, bool $maybeTransparent): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($maybeTransparent && $this->hasTransparency($image)) {
            imagesavealpha($image, true);

            return ['contents' => $this->write(fn () => imagepng($image, null, 6)), 'extension' => 'png', 'mime' => 'image/png', 'width' => $width, 'height' => $height];
        }

        return ['contents' => $this->write(fn () => imagejpeg($image, null, 82)), 'extension' => 'jpg', 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height];
    }

    private function hasTransparency(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
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
    private function write(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
