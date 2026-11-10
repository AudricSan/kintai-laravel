<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 2 : segment qualifié « shift » ou « absence », avec son score de confiance (0 à 100).
 */
final readonly class QualifiedSegment
{
    public const SHIFT = 'shift';

    public const ABSENCE = 'absence';

    /**
     * @param  array<string, bool>  $flags
     */
    public function __construct(
        public Segment $segment,
        public string $type,
        public int $confidence,
        public string $reason,
        public array $flags = [],
    ) {}

    public function isShift(): bool
    {
        return $this->type === self::SHIFT;
    }
}
