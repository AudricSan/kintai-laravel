<?php

namespace Kintai\Bundles\PlanningImport\Detection;

/**
 * Phase 3 : déduplication stricte. Seuls les shifts sont gardés (les absences disparaissent) ; la clé « nom|date|début|fin »
 * identifie un doublon, la première occurrence est gardée et les doublons sont comptés.
 */
final class StrictDeduplicator
{
    /**
     * @param  list<QualifiedSegment>  $qualified
     * @return list<DetectedShift>
     */
    public function deduplicate(array $qualified): array
    {
        $first = [];
        $duplicates = [];

        foreach ($qualified as $item) {
            if (! $item->isShift()) {
                continue;
            }

            $key = self::key($item->segment);
            if (isset($first[$key])) {
                $duplicates[$key]++;

                continue;
            }

            $first[$key] = $item;
            $duplicates[$key] = 0;
        }

        $shifts = [];
        foreach ($first as $key => $item) {
            $shifts[] = new DetectedShift($item->segment, $item->confidence, (string) $key, $duplicates[$key]);
        }

        return $shifts;
    }

    public static function key(Segment $segment): string
    {
        $name = $segment->employeeName !== null && trim($segment->employeeName) !== '' ? trim($segment->employeeName) : 'UNKNOWN';

        return implode('|', [$name, trim($segment->date), trim($segment->startTime), trim($segment->endTime)]);
    }
}
