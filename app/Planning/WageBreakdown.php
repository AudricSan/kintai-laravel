<?php

namespace App\Planning;

/**
 * Coût estimé d'un shift, décomposé par tranche horaire.
 */
final readonly class WageBreakdown
{
    /**
     * @param  list<WageSlice>  $slices  Triées par identifiant de type.
     * @param  float  $rate  Taux de la tranche dominante (ou taux plat imposé), 0 sans tranche.
     */
    public function __construct(
        public array $slices,
        public int $netMinutes,
        public float $total,
        public float $rate,
        public ?int $dominantTypeId,
    ) {}
}
