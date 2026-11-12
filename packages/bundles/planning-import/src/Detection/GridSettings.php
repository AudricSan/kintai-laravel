<?php

namespace Kintai\Bundles\PlanningImport\Detection;

use Kintai\Bundles\PlanningImport\Models\StoreImportSetting;

/**
 * Grille du fichier d'un magasin. Les défauts correspondent au planning japonais standard : colonne D (4) à AZ (52),
 * 06:00 pour la première colonne, 30 minutes par colonne, un bloc de 9 lignes par jour dont 7 lignes de shifts.
 * Les valeurs hors limites reviennent à une valeur sûre, comme à l'ancienne version.
 */
final readonly class GridSettings
{
    public const DEFAULT_PATTERN = '^\d{4}$';

    /** Lignes du bloc où chercher la date (colonne A), dans cet ordre. */
    public const DATE_ROW_OFFSETS = [2, 0, 1, 3];

    public int $colStart;

    public int $colEnd;

    public int $baseHour;

    public int $minutesPerCol;

    public int $blockSize;

    public int $shiftRows;

    public function __construct(
        int $colStart = 4,
        int $colEnd = 52,
        int $baseHour = 6,
        int $minutesPerCol = 30,
        int $blockSize = 9,
        int $shiftRows = 7,
        public string $sheetPattern = self::DEFAULT_PATTERN,
        public int $autoPauseAfterMinutes = 0,
        public int $autoPauseMinutes = 30,
    ) {
        $this->colStart = max(1, $colStart);
        $this->colEnd = max($this->colStart, $colEnd);
        $this->baseHour = $baseHour < 0 || $baseHour > 23 ? 6 : $baseHour;
        $this->minutesPerCol = $minutesPerCol < 1 ? 30 : $minutesPerCol;
        $this->blockSize = max(2, $blockSize);
        $this->shiftRows = max(1, $shiftRows);
    }

    public static function forStore(int $storeId): self
    {
        $row = StoreImportSetting::query()->where('store_id', $storeId)->first();

        return $row === null ? new self : new self(
            $row->col_start, $row->col_end, $row->base_hour, $row->minutes_per_col, $row->block_size, $row->shift_rows,
            $row->sheet_filter_pattern, $row->auto_pause_after_minutes, $row->auto_pause_minutes,
        );
    }

    /**
     * Pause automatique en minutes pour un shift de cette durée (0 : désactivée ou durée trop courte).
     */
    public function pauseFor(int $durationMinutes): int
    {
        return $this->autoPauseAfterMinutes > 0 && $durationMinutes >= $this->autoPauseAfterMinutes ? $this->autoPauseMinutes : 0;
    }

    /**
     * Expression régulière complète d'un motif de feuille, ou null s'il est invalide.
     */
    public static function regex(string $pattern): ?string
    {
        $regex = '~'.str_replace('~', '\~', $pattern).'~u';

        return @preg_match($regex, '') === false ? null : $regex;
    }
}
