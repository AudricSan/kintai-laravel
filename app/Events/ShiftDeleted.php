<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un shift vient d'être supprimé.
 */
class ShiftDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId) {}
}
