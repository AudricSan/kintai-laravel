<?php

namespace App\Planning;

/**
 * Problème relevé par le garde-fou avant l'enregistrement d'un shift.
 */
final readonly class ShiftIssue
{
    public function __construct(
        public ShiftIssueKind $kind,
        public string $message,
        public ?int $conflictingShiftId = null,
    ) {}

    public function isBlocking(): bool
    {
        return $this->kind->isBlocking();
    }
}
