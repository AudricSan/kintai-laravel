<?php

declare(strict_types=1);

namespace kintai\Contract\Event;

/**
 * Événement « shift enregistré » (contrat 1.28), annoncé par le Core aux bundles qui l'écoutent (`Bundle::eventListeners()`)
 * juste après l'écriture d'un shift par le dépôt : quel que soit le chemin (administration, API, import Excel, échange,
 * reprise d'un shift ouvert). Un bundle qui garde une copie du planning (agenda externe, export) s'y appuie plutôt que de
 * surveiller chaque contrôleur.
 */
final class ShiftSaved
{
    /**
     * @param array<string, mixed>      $shift  Ligne du shift telle qu'enregistrée
     * @param array<string, mixed>|null $before Ligne avant la modification, null pour un shift créé
     */
    public function __construct(
        public readonly array $shift,
        public readonly ?array $before = null,
    ) {}

    public function isNew(): bool
    {
        return $this->before === null;
    }

    /** Employé du shift après l'écriture (0 : shift ouvert, non attribué). */
    public function userId(): int
    {
        return (int) ($this->shift['user_id'] ?? 0);
    }

    /** Employé avant l'écriture, s'il a changé (le shift lui a été retiré) ; null sinon. */
    public function previousUserId(): ?int
    {
        $previous = (int) ($this->before['user_id'] ?? 0);
        return $this->before !== null && $previous > 0 && $previous !== $this->userId() ? $previous : null;
    }
}
