<?php

namespace Kintai\Contracts\Events;

/**
 * Un shift vient d'être restauré.
 */
final class ShiftRestored
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId, public readonly ?int $actorId = null) {}
}
