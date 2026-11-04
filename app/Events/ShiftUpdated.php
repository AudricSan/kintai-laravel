<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un shift vient d'être modifié.
 */
class ShiftUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId) {}
}
