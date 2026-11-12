<?php

namespace Kintai\Contracts\Events;

/**
 * Un type de shift vient d'être modifié.
 */
final class ShiftTypeUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $shiftTypeId) {}
}
