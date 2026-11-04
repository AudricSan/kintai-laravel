<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un type de shift vient d'être créé.
 */
class ShiftTypeCreated
{
    use Dispatchable;

    public function __construct(public readonly int $shiftTypeId) {}
}
