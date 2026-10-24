<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase2;

use kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO;
use kintai\Core\Services\ExcelShiftImport\DTOs\QualifiedSegmentDTO;

/**
 * BusinessQualifier — Phase 2 : qualification métier
 *
 * - Fond blanc  → ABSENCE par défaut, sauf si WhiteBackgroundRule est satisfaite
 * - Fond coloré → SHIFT par défaut, sauf si nom invalide
 */
final class BusinessQualifier
{
    public function __construct(
        private WhiteBackgroundRule $whiteRule,
        private ConfidenceScorer    $scorer
    ) {}

    /** @param SegmentDTO[] $segments */
    public function qualify(array $segments): array
    {
        return array_map(fn(SegmentDTO $s) => $this->qualifySingle($s), $segments);
    }

    public function qualifySingle(SegmentDTO $segment): QualifiedSegmentDTO
    {
        return $segment->isWhite()
            ? $this->qualifyWhite($segment)
            : $this->qualifyColored($segment);
    }

    private function qualifyWhite(SegmentDTO $segment): QualifiedSegmentDTO
    {
        $validation = $this->whiteRule->validate($segment);
        $type       = $validation['is_shift'] ? 'shift' : 'absence';
        $score      = $this->scorer->calculateScore($segment, $type);

        $reason = $validation['is_shift']
            ? 'Fond blanc qualifié comme shift : ' . implode(', ', $validation['reasons'])
            : 'Fond blanc marqué comme absence : ' . implode(', ', $validation['reasons']);

        return new QualifiedSegmentDTO($segment, $type, $score, $reason, $validation['validation_flags']);
    }

    private function qualifyColored(SegmentDTO $segment): QualifiedSegmentDTO
    {
        $hasName = $this->hasValidName($segment);
        $type    = $hasName ? 'shift' : 'absence';
        $score   = $this->scorer->calculateScore($segment, $type);

        if ($hasName) {
            $reason = sprintf(
                'Segment coloré avec nom valide "%s" (durée : %.1fh)',
                $segment->employeeName,
                $segment->getDurationHours()
            );
        } else {
            $reason = sprintf(
                'Segment coloré rejeté : nom invalide ("%s")',
                $segment->employeeName ?? 'null'
            );
        }

        return new QualifiedSegmentDTO($segment, $type, $score, $reason, [
            'is_white'      => false,
            'has_valid_name'=> $hasName,
            'has_color'     => true,
        ]);
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
