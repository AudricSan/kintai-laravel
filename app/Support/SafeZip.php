<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Extraction d'archive ZIP après contrôle de chaque entrée (audit SEC-09) : refuse les chemins absolus, les « .. », les
 * octets NUL, les antislashs, les lettres de lecteur et les liens symboliques, et borne le nombre d'entrées, la taille
 * décompressée totale et le taux de compression (bombes ZIP). Rien n'est écrit tant que toutes les entrées ne sont pas
 * acceptées.
 */
final class SafeZip
{
    public const MAX_ENTRIES = 20000;
    public const MAX_TOTAL_BYTES = 536870912; // 512 Mo décompressés
    public const MAX_RATIO = 200;

    /** @throws RuntimeException archive refusée */
    public static function extract(ZipArchive $zip, string $destination, int $maxBytes = self::MAX_TOTAL_BYTES): void
    {
        self::assertSafe($zip, $maxBytes);
        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException('Dossier d\'extraction impossible à créer.');
        }
        if (!$zip->extractTo($destination)) {
            throw new RuntimeException('Extraction de l\'archive impossible.');
        }
    }

    /** @throws RuntimeException */
    public static function assertSafe(ZipArchive $zip, int $maxBytes = self::MAX_TOTAL_BYTES): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('Archive refusée : trop d\'entrées.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RuntimeException('Archive refusée : entrée illisible.');
            }
            $name = (string) $stat['name'];
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
                || preg_match('#^[A-Za-z]:#', $name) === 1) {
                throw new RuntimeException('Archive refusée : chemin d\'entrée invalide.');
            }
            foreach (explode('/', $name) as $segment) {
                if ($segment === '..') {
                    throw new RuntimeException('Archive refusée : chemin d\'entrée hors du dossier.');
                }
            }

            $opsys = 0;
            $attr  = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
                && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('Archive refusée : lien symbolique.');
            }

            $size = (int) $stat['size'];
            $comp = (int) $stat['comp_size'];
            $total += $size;
            if ($total > $maxBytes) {
                throw new RuntimeException('Archive refusée : taille décompressée trop grande.');
            }
            if ($size > 1048576 && $comp > 0 && intdiv($size, $comp) > self::MAX_RATIO) {
                throw new RuntimeException('Archive refusée : taux de compression suspect.');
            }
        }
    }
}
