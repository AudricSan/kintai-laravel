<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 2 : qualification métier. Fond blanc : absence sauf si `WhiteBackgroundRule` est satisfaite ; fond coloré : shift
 * sauf si le nom est invalide.
 */
final class BusinessQualifier
{
    public function __construct(
        private readonly WhiteBackgroundRule $whiteRule = new WhiteBackgroundRule,
        private readonly ConfidenceScorer $scorer = new ConfidenceScorer,
    ) {}

    /**
     * @param  list<Segment>  $segments
     * @return list<QualifiedSegment>
     */
    public function qualify(array $segments): array
    {
        return array_map($this->qualifyOne(...), $segments);
    }

    public function qualifyOne(Segment $segment): QualifiedSegment
    {
        if ($segment->isWhite()) {
            $check = $this->whiteRule->validate($segment);
            $type = $check['is_shift'] ? QualifiedSegment::SHIFT : QualifiedSegment::ABSENCE;

            return new QualifiedSegment($segment, $type, $this->scorer->score($segment, $type), 'white background: '.implode(', ', $check['reasons']), $check['flags']);
        }

        $valid = $segment->hasValidName();
        $type = $valid ? QualifiedSegment::SHIFT : QualifiedSegment::ABSENCE;

        return new QualifiedSegment(
            $segment, $type, $this->scorer->score($segment, $type),
            $valid ? 'colored segment with a valid name' : 'colored segment rejected: invalid name',
            ['is_white' => false, 'has_valid_name' => $valid, 'has_color' => true],
        );
    }
}
