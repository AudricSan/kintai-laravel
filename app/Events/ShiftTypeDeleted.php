<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un type de shift vient d'être supprimé ; son nom et son code sont gardés car la ligne n'existe plus.
 */
class ShiftTypeDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $shiftTypeId,
        public readonly ?string $name = null,
        public readonly ?string $code = null,
    ) {}
}
