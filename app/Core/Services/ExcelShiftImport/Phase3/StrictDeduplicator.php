<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase3;

use kintai\Core\Services\ExcelShiftImport\DTOs\QualifiedSegmentDTO;
use kintai\Core\Services\ExcelShiftImport\DTOs\DeduplicatedShiftDTO;

/**
 * StrictDeduplicator — Phase 3 : déduplication stricte
 *
 * - Filtre uniquement les shifts (les absences sont ignorées)
 * - Garde la première occurrence de chaque clé
 * - Compte les doublons pour les statistiques
 */
final class StrictDeduplicator
{
    public function __construct(private DeduplicationKey $keyGen) {}

    /**
     * @param QualifiedSegmentDTO[] $qualified
     * @return DeduplicatedShiftDTO[]
     */
    public function deduplicate(array $qualified): array
    {
        $shifts          = array_values(array_filter($qualified, fn($q) => $q->isShift()));
        $seen            = [];
        $firstOccurrence = []; // key → index dans $results
        $duplicateCount  = []; // key → nb de doublons
        $results         = [];

        foreach ($shifts as $q) {
            $key = $this->keyGen->generate(
                $q->segment->employeeName,
                $q->segment->date,
                $q->segment->startTime,
                $q->segment->endTime
            );

            if (!isset($seen[$key])) {
                $seen[$key]            = true;
                $firstOccurrence[$key] = count($results);
                $duplicateCount[$key]  = 0;
                $results[]             = [$q, $key];
            } else {
                $duplicateCount[$key]++;
            }
        }

        // Reconstruire les DTOs immutables avec le bon nombre de doublons
        $final = [];
        foreach ($results as [$q, $key]) {
            $final[] = new DeduplicatedShiftDTO(
                segment:          $q->segment,
                qualifiedType:    $q->type,
                confidenceScore:  $q->confidenceScore,
                deduplicationKey: $key,
                duplicateCount:   $duplicateCount[$key] ?? 0
            );
        }

        return $final;
    }
}
