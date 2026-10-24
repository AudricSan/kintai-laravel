<?php

/**
 * Génère tout le jeu d'icônes de l'app (favicon d'onglet + icônes PWA
 * installables) à partir du logo "Kintai" (public/assets/img/kintai-wordmark.png,
 * fond transparent). Le glyphe "K" isolé est extrait automatiquement — c'est le
 * premier caractère du mot, une forme déjà quasi carrée qui fonctionne bien
 * comme icône autonome.
 *
 * Usage :
 *   php scripts/generate-favicons.php
 *
 * Script one-shot (pas exécuté au runtime) : à relancer manuellement si le
 * logo source est retouché. GD n'ayant pas d'encodeur ICO natif, favicon.ico
 * est écrit à la main (en-tête ICONDIR/ICONDIRENTRY + frames PNG embarquées,
 * format supporté nativement par tous les navigateurs/OS modernes).
 */

declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/public/assets/img/kintai-wordmark.png';
$outputDir  = dirname(__DIR__) . '/public/assets/img/';

const ICON_BACKGROUND = [255, 255, 255]; // blanc — apple-touch-icon/PWA n'aiment pas la transparence
const MASKABLE_SAFE_ZONE = 0.8; // le motif doit tenir dans ce ratio du canevas (zone de sécurité "maskable")

if (!is_file($sourcePath)) {
    fwrite(STDERR, "Source introuvable : {$sourcePath}\n");
    exit(1);
}

$wordmark = imagecreatefrompng($sourcePath);
if (!$wordmark instanceof \GdImage) {
    fwrite(STDERR, "Impossible de décoder {$sourcePath}\n");
    exit(1);
}

/**
 * Isole le premier glyphe (segment de colonnes non-transparentes) du wordmark
 * — le "K" de "Kintai" — et le retourne sur un canevas carré transparent,
 * centré, avec une marge de $paddingRatio autour de sa bounding box réelle.
 */
function extractFirstGlyphSquare(\GdImage $wordmark, float $paddingRatio = 0.04): \GdImage
{
    $w = imagesx($wordmark);
    $h = imagesy($wordmark);

    // Colonne de fin du premier segment opaque (le K se termine avant le
    // premier "trou" transparent qui sépare les lettres/accents suivants).
    $glyphEndX = null;
    $seenPixel = false;
    for ($x = 0; $x < $w; $x++) {
        $hasPixel = columnHasOpaquePixel($wordmark, $x, $h);
        if ($hasPixel) {
            $seenPixel = true;
        } elseif ($seenPixel) {
            $glyphEndX = $x - 1;
            break;
        }
    }
    if ($glyphEndX === null) {
        $glyphEndX = $w - 1;
    }

    // Bounding box verticale réelle du glyphe (au cas où il ne couvrirait pas
    // toute la hauteur du canevas source).
    $minY = $h;
    $maxY = 0;
    for ($x = 0; $x <= $glyphEndX; $x++) {
        for ($y = 0; $y < $h; $y++) {
            if (isOpaque($wordmark, $x, $y)) {
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }
    }

    $glyphW = $glyphEndX + 1;
    $glyphH = $maxY - $minY + 1;
    $side   = (int) round(max($glyphW, $glyphH) * (1 + $paddingRatio));

    $square = imagecreatetruecolor($side, $side);
    imagealphablending($square, false);
    imagesavealpha($square, true);
    $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
    imagefill($square, 0, 0, $transparent);

    $destX = (int) round(($side - $glyphW) / 2);
    $destY = (int) round(($side - $glyphH) / 2);
    imagealphablending($square, true);
    imagecopy($square, $wordmark, $destX, $destY, 0, $minY, $glyphW, $glyphH);

    return $square;
}

function isOpaque(\GdImage $image, int $x, int $y): bool
{
    $color = imagecolorat($image, $x, $y);
    $alpha = ($color >> 24) & 0x7F;
    return $alpha < 100;
}

function columnHasOpaquePixel(\GdImage $image, int $x, int $h): bool
{
    for ($y = 0; $y < $h; $y += 2) {
        if (isOpaque($image, $x, $y)) {
            return true;
        }
    }
    return false;
}

/** Redimensionne $source (carré) vers $size×$size, en conservant l'alpha. */
function renderSquarePng(\GdImage $source, int $size): \GdImage
{
    $resized = imagecreatetruecolor($size, $size);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefill($resized, 0, 0, $transparent);

    imagecopyresampled($resized, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

    return $resized;
}

/** Aplati $glyph (transparent) sur un fond opaque $size×$size, avec une marge optionnelle. */
function renderOnOpaqueBackground(\GdImage $glyph, int $size, array $rgb, float $safeZone = 1.0): \GdImage
{
    $canvas = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
    imagefill($canvas, 0, 0, $bg);

    $glyphSize = (int) round($size * $safeZone);
    $resizedGlyph = renderSquarePng($glyph, $glyphSize);

    $offset = (int) round(($size - $glyphSize) / 2);
    imagecopy($canvas, $resizedGlyph, $offset, $offset, 0, 0, $glyphSize, $glyphSize);
    imagedestroy($resizedGlyph);

    return $canvas;
}

/** Assemble un .ico à partir de plusieurs images GD, une frame PNG par taille. */
function writeIco(array $images, string $destPath): void
{
    $count = count($images);
    $header = pack('vvv', 0, 1, $count);

    $entries = '';
    $data    = '';
    $offset  = 6 + $count * 16;

    foreach ($images as $image) {
        $size = imagesx($image);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $dim = $size >= 256 ? 0 : $size; // 0 = 256px en encodage ICO
        $entries .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($png), $offset);
        $data    .= $png;
        $offset  += strlen($png);
    }

    file_put_contents($destPath, $header . $entries . $data);
}

$glyph = extractFirstGlyphSquare($wordmark);
imagedestroy($wordmark);
echo "Glyphe K isolé : " . imagesx($glyph) . "x" . imagesy($glyph) . " (carré, fond transparent)\n";

// --- Favicon d'onglet (transparent) ---
$transparentSizes = [16 => 'favicon-16.png', 32 => 'favicon-32.png'];
$icoSizes  = [16, 32, 48];
$icoImages = [];

foreach ($transparentSizes as $size => $filename) {
    $resized = renderSquarePng($glyph, $size);
    imagepng($resized, $outputDir . $filename, 9);
    echo "  - {$filename} ({$size}x{$size}, transparent)\n";
    $icoImages[$size] = $resized;
}
foreach ($icoSizes as $size) {
    if (!isset($icoImages[$size])) {
        $icoImages[$size] = renderSquarePng($glyph, $size);
    }
}
ksort($icoImages);
writeIco(array_values($icoImages), $outputDir . 'favicon.ico');
echo "  - favicon.ico (" . implode('/', $icoSizes) . ", transparent)\n";
foreach ($icoImages as $image) {
    imagedestroy($image);
}

// --- Icônes opaques (fond blanc) : apple-touch-icon + PWA ---
$opaqueTargets = [
    'apple-touch-icon.png' => ['size' => 180, 'safeZone' => 0.82],
    'kintai-192.png'       => ['size' => 192, 'safeZone' => 0.82],
    'kintai-512.png'       => ['size' => 512, 'safeZone' => 0.82],
];

foreach ($opaqueTargets as $filename => $opts) {
    $canvas = renderOnOpaqueBackground($glyph, $opts['size'], ICON_BACKGROUND, $opts['safeZone']);
    imagepng($canvas, $outputDir . $filename, 9);
    echo "  - {$filename} ({$opts['size']}x{$opts['size']}, fond blanc)\n";
    imagedestroy($canvas);
}

// Maskable : zone de sécurité plus large (le motif doit survivre un rognage
// circulaire/squircle par l'OS, cf. spec web app manifest "maskable").
$maskable = renderOnOpaqueBackground($glyph, 512, ICON_BACKGROUND, MASKABLE_SAFE_ZONE);
imagepng($maskable, $outputDir . 'kintai-512-maskable.png', 9);
echo "  - kintai-512-maskable.png (512x512, fond blanc, safe zone " . (MASKABLE_SAFE_ZONE * 100) . "%)\n";
imagedestroy($maskable);

imagedestroy($glyph);

echo "Icônes générées dans {$outputDir}\n";
