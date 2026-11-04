<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un type de shift vient d'être modifié.
 */
class ShiftTypeUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $shiftTypeId) {}
}
