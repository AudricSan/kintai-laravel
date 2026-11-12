<?php

namespace Kintai\Contracts\Events;

/**
 * Un shift vient d'être créé.
 */
final class ShiftCreated
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId, public readonly ?int $actorId = null) {}
}
