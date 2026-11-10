<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 2 : un segment à fond blanc est un shift si et seulement si son nom est valide, sa durée d'au moins 4 heures et
 * ses bordures haut et bas présentes ; sinon c'est une absence.
 */
final class WhiteBackgroundRule
{
    public const MIN_MINUTES = 240;

    /**
     * @return array{is_shift: bool, reasons: list<string>, flags: array<string, bool>}
     */
    public function validate(Segment $segment): array
    {
        $name = $segment->hasValidName();
        $duration = $segment->durationMinutes >= self::MIN_MINUTES;
        $borders = $segment->hasTopBorder && $segment->hasBottomBorder;
        $isShift = $name && $duration && $borders;

        $reasons = [];
        if ($isShift) {
            $reasons[] = 'valid name, long enough, borders';
        } else {
            if (! $name) {
                $reasons[] = 'missing or invalid name';
            }
            if (! $duration) {
                $reasons[] = sprintf('too short: %d min (required: %d min)', $segment->durationMinutes, self::MIN_MINUTES);
            }
            if (! $borders) {
                $reasons[] = 'missing borders';
            }
        }

        return [
            'is_shift' => $isShift,
            'reasons' => $reasons,
            'flags' => ['is_white' => true, 'has_valid_name' => $name, 'meets_duration' => $duration, 'has_borders' => $borders],
        ];
    }
}
