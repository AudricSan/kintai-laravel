<?php

namespace App\Exceptions;

use RuntimeException;

class ShiftRestoreException extends RuntimeException
{
    public static function replacedByActiveShift(int $shiftId, int $replacementId): self
    {
        return new self("Le shift {$shiftId} ne peut pas être restauré : il est remplacé par le shift actif {$replacementId}.");
    }
}
