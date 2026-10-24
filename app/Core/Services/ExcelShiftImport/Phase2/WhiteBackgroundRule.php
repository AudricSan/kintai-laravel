<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase2;

use kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO;

/**
 * WhiteBackgroundRule — règle critique pour les fonds blancs
 *
 * Un segment à fond blanc est un SHIFT si et seulement si :
 * 1. Nom d'employé valide (non nul, non numérique, longueur > 1)
 * 2. Durée >= 4 heures (240 minutes)
 * 3. Bordures haut ET bas présentes
 *
 * Si une condition échoue → ABSENCE.
 */
final class WhiteBackgroundRule
{
    private const MIN_SHIFT_MINUTES = 240; // 4 heures

    public function validate(SegmentDTO $segment): array
    {
        $hasValidName  = $this->hasValidName($segment);
        $meetsDuration = $segment->durationMinutes >= self::MIN_SHIFT_MINUTES;
        $hasBorders    = $segment->hasTopBorder && $segment->hasBottomBorder;

        $isShift = $hasValidName && $meetsDuration && $hasBorders;

        $reasons      = [];
        $failedChecks = [];

        if ($isShift) {
            $reasons[] = 'Fond blanc avec nom valide, durée suffisante et bordures';
        } else {
            if (!$hasValidName) {
                $reasons[]      = 'Nom d\'employé manquant ou invalide';
                $failedChecks[] = 'employee_name';
            }
            if (!$meetsDuration) {
                $reasons[]      = sprintf(
                    'Durée trop courte : %d min (requis : %d min / %.1fh)',
                    $segment->durationMinutes,
                    self::MIN_SHIFT_MINUTES,
                    self::MIN_SHIFT_MINUTES / 60
                );
                $failedChecks[] = 'duration';
            }
            if (!$hasBorders) {
                $details = [];
                if (!$segment->hasTopBorder)    $details[] = 'bordure haut manquante';
                if (!$segment->hasBottomBorder) $details[] = 'bordure bas manquante';
                $reasons[]      = 'Bordures manquantes : ' . implode(', ', $details);
                $failedChecks[] = 'borders';
            }
        }

        return [
            'is_shift'         => $isShift,
            'reasons'          => $reasons,
            'failed_checks'    => $failedChecks,
            'validation_flags' => [
                'is_white'         => true,
                'has_valid_name'   => $hasValidName,
                'meets_duration'   => $meetsDuration,
                'has_borders'      => $hasBorders,
            ],
        ];
    }

    private function hasValidName(SegmentDTO $segment): bool
    {
        $name = $segment->employeeName;
        if ($name === null || trim($name) === '') return false;
        $trimmed = trim($name);
        if (preg_match('/^\d+$/', $trimmed)) return false;
        if (mb_strlen($trimmed) <= 1)          return false;
        return true;
    }
}
