<?php

declare(strict_types=1);

namespace kintai\Contract\Event;

/**
 * Événement « shift supprimé » (contrat 1.28), annoncé par le Core juste après la suppression d'un shift par le dépôt, quel
 * que soit le chemin. Voir ShiftSaved.
 */
final class ShiftDeleted
{
    /** @param array<string, mixed> $shift Ligne du shift telle qu'elle était avant la suppression */
    public function __construct(public readonly array $shift) {}

    public function shiftId(): int
    {
        return (int) ($this->shift['id'] ?? 0);
    }

    public function userId(): int
    {
        return (int) ($this->shift['user_id'] ?? 0);
    }
}
