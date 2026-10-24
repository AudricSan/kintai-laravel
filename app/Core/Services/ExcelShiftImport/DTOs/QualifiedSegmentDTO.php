<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\DTOs;

/**
 * Qualified Segment DTO — Phase 2
 *
 * Segment qualifié par les règles métier : 'shift' ou 'absence'.
 */
final class QualifiedSegmentDTO
{
    public function __construct(
        public readonly SegmentDTO $segment,
        public readonly string     $type,           // 'shift' | 'absence'
        public readonly int        $confidenceScore, // 0-100
        public readonly string     $reason,
        public readonly array      $validationFlags
    ) {
        if (!in_array($type, ['shift', 'absence'], true)) {
            throw new \InvalidArgumentException("Type must be 'shift' or 'absence', got: {$type}");
        }
        if ($confidenceScore < 0 || $confidenceScore > 100) {
            throw new \InvalidArgumentException("Confidence score must be 0-100, got: {$confidenceScore}");
        }
    }

    public function isShift(): bool   { return $this->type === 'shift'; }
    public function isAbsence(): bool { return $this->type === 'absence'; }

    public function isHighConfidence(): bool { return $this->confidenceScore >= 70; }

    public function toArray(): array
    {
        return [
            'type'             => $this->type,
            'confidence_score' => $this->confidenceScore,
            'reason'           => $this->reason,
            'validation_flags' => $this->validationFlags,
            'segment'          => $this->segment->toArray(),
        ];
    }
}
