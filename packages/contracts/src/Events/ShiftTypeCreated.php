<?php

namespace Kintai\Contracts\Events;

/**
 * Un type de shift vient d'être créé.
 */
final class ShiftTypeCreated
{
    use Dispatchable;

    public function __construct(public readonly int $shiftTypeId) {}
}
