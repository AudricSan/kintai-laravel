<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 2 : score de confiance de 0 à 100. Nom valide 40 points, bordures haut et bas 20, durée d'au moins 4 h 10 points,
 * d'au moins 8 h 10 de plus. Pour une absence le score est inversé : plus il est haut, moins le segment ressemble à un shift.
 */
final class ConfidenceScorer
{
    private const MAX = 80;

    public function score(Segment $segment, string $type): int
    {
        $score = ($segment->hasValidName() ? 40 : 0)
            + ($segment->hasTopBorder && $segment->hasBottomBorder ? 20 : 0)
            + ($segment->durationMinutes >= 240 ? 10 : 0)
            + ($segment->durationMinutes >= 480 ? 10 : 0);

        return (int) min(100, (($type === QualifiedSegment::ABSENCE ? self::MAX - $score : $score) / self::MAX) * 100);
    }
}
