<?php

namespace Kintai\Contracts\Events;

/**
 * Un shift vient d'être supprimé.
 */
final class ShiftDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $shiftId, public readonly ?int $actorId = null) {}
}
