<?php

namespace Kintai\Contracts\Events;

/**
 * Un shift en a remplacé un autre : l'ancien est dans la corbeille, le nouveau fait foi.
 */
final class ShiftReplaced
{
    use Dispatchable;

    public function __construct(
        public readonly int $replacedShiftId,
        public readonly int $replacementShiftId,
        public readonly ?int $actorId = null,
    ) {}
}
