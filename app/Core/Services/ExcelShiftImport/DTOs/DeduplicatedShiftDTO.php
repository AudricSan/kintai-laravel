<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\DTOs;

/**
 * Deduplicated Shift DTO — Phase 3
 *
 * Shift unique après déduplication, prêt pour la persistance.
 */
final class DeduplicatedShiftDTO
{
    public function __construct(
        public readonly SegmentDTO $segment,
        public readonly string     $qualifiedType,
        public readonly int        $confidenceScore,
        public readonly string     $deduplicationKey,
        public readonly int        $duplicateCount = 0
    ) {
        if ($qualifiedType !== 'shift') {
            throw new \InvalidArgumentException("DeduplicatedShiftDTO must be type 'shift', got: {$qualifiedType}");
        }
    }

    public function hasDuplicates(): bool  { return $this->duplicateCount > 0; }
    public function getEmployeeName(): ?string { return $this->segment->employeeName; }
    public function getDate(): string      { return $this->segment->date; }
    public function getStartTime(): string { return $this->segment->startTime; }
    public function getEndTime(): string   { return $this->segment->endTime; }

    public function toArray(): array
    {
        return [
            'qualified_type'    => $this->qualifiedType,
            'confidence_score'  => $this->confidenceScore,
            'deduplication_key' => $this->deduplicationKey,
            'duplicate_count'   => $this->duplicateCount,
            'had_duplicates'    => $this->hasDuplicates(),
            'segment'           => $this->segment->toArray(),
        ];
    }
}
