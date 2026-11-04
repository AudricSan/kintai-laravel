<?php

namespace App\Planning;

use App\Models\Shift;

/**
 * Deux shifts d'un même employé qui se chevauchent.
 */
final readonly class ShiftConflict
{
    public function __construct(public Shift $first, public Shift $second, public int $overlapMinutes) {}

    /**
     * Le plus récent (identifiant le plus élevé) : celui qu'on garde.
     */
    public function newer(): Shift
    {
        return $this->first->id > $this->second->id ? $this->first : $this->second;
    }

    public function older(): Shift
    {
        return $this->first->id > $this->second->id ? $this->second : $this->first;
    }
}
