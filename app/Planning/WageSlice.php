<?php

namespace App\Planning;

/**
 * Une tranche du coût d'un shift : minutes payées dans un type, au taux appliqué.
 */
final readonly class WageSlice
{
    public function __construct(
        public ?int $shiftTypeId,
        public string $name,
        public int $minutes,
        public float $rate,
        public float $amount,
    ) {}
}
