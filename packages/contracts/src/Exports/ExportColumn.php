<?php

namespace Kintai\Contracts\Exports;

/**
 * Une colonne d'un jeu de données : sa clé dans les lignes, son libellé (clé de traduction) et son type, qui décide
 * de la mise en forme (cellule Excel typée, alignement du PDF, neutralisation des formules du CSV).
 */
final readonly class ExportColumn
{
    /** Texte libre (neutralisé dans le CSV, toujours du texte dans Excel). */
    public const TEXT = 'text';

    /** Jour « AAAA-MM-JJ » (vraie date dans Excel). */
    public const DATE = 'date';

    /** Heure « HH:MM », gardée en texte. */
    public const TIME = 'time';

    public const INTEGER = 'integer';

    /** Nombre à deux décimales (montants, taux). */
    public const DECIMAL = 'decimal';

    /** Écrit « Oui » / « Non ». */
    public const BOOLEAN = 'boolean';

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
    ) {}

    public function isNumeric(): bool
    {
        return $this->type === self::INTEGER || $this->type === self::DECIMAL;
    }
}
