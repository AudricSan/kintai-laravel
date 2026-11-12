<?php

namespace App\Ical;

/**
 * Règles de texte de la RFC 5545 : échappement des valeurs et repli des lignes.
 */
final class IcalText
{
    /** Longueur maximale d'une ligne en octets, saut de ligne exclu (RFC 5545, 3.1). */
    public const MAX_LINE_OCTETS = 75;

    /**
     * Échappe une valeur TEXT : \ ; , et les sauts de ligne.
     */
    public static function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /**
     * Replie une ligne à 75 octets (l'espace de continuation compte), sans couper un caractère UTF-8.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }

        $lines = [];
        $limit = self::MAX_LINE_OCTETS;

        while (strlen($line) > $limit) {
            $cut = $limit;
            // Recule tant que l'octet suivant est un octet de continuation UTF-8 (10xxxxxx).
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $lines[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            // Les lignes de continuation commencent par une espace, qui compte dans la limite.
            $limit = self::MAX_LINE_OCTETS - 1;
        }

        $lines[] = $line;

        return implode("\r\n ", $lines);
    }
}
