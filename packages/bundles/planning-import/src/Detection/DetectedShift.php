<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 3 : shift unique après déduplication stricte.
 */
final readonly class DetectedShift
{
    public function __construct(
        public Segment $segment,
        public int $confidence,
        public string $key,
        public int $duplicates = 0,
    ) {}
}
