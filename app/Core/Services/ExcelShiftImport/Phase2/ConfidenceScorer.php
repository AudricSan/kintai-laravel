<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase2;

use kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO;

/**
 * ConfidenceScorer — score de confiance 0-100
 *
 * Critères :
 * - Nom valide      : +40 pts
 * - Bordures ↑↓     : +20 pts
 * - Durée ≥ 4h      : +10 pts
 * - Durée ≥ 8h      : +10 pts (bonus)
 */
final class ConfidenceScorer
{
    private const PTS_NAME     = 40;
    private const PTS_BORDERS  = 20;
    private const PTS_DUR_4H   = 10;
    private const PTS_DUR_8H   = 10;
    private const MAX_SCORE    = self::PTS_NAME + self::PTS_BORDERS + self::PTS_DUR_4H + self::PTS_DUR_8H; // 80

    public function calculateScore(SegmentDTO $segment, string $qualifiedType): int
    {
        $score = 0;

        if ($this->hasValidName($segment)) $score += self::PTS_NAME;
        if ($segment->hasTopBorder && $segment->hasBottomBorder) $score += self::PTS_BORDERS;
        if ($segment->durationMinutes >= 240) $score += self::PTS_DUR_4H;
        if ($segment->durationMinutes >= 480) $score += self::PTS_DUR_8H;

        if ($qualifiedType === 'absence') {
            // Plus le score est bas, plus c'est probablement une absence
            $absenceScore = self::MAX_SCORE - $score;
            return (int) min(100, ($absenceScore / self::MAX_SCORE) * 100);
        }

        return (int) min(100, ($score / self::MAX_SCORE) * 100);
    }

    private function hasValidName(SegmentDTO $segment): bool
    {
        $name = $segment->employeeName;
        if ($name === null || trim($name) === '') return false;
        $t = trim($name);
        if (preg_match('/^\d+$/', $t)) return false;
        if (mb_strlen($t) <= 1)        return false;
        return true;
    }
}
