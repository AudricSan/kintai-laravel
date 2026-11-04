<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un type de shift vient d'être supprimé.
 */
class ShiftTypeDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $shiftTypeId) {}
}
