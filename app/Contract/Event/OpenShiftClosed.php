<?php

declare(strict_types=1);

namespace kintai\Contract\Event;

/**
 * Événement « shift ouvert fermé » (contrat 1.17), annoncé par le Core aux bundles qui l'écoutent
 * (`Bundle::eventListeners()`) quand un shift publié à la bourse cesse de l'être depuis le formulaire de shift ou
 * l'API (`is_open` passe de 1 à 0).
 *
 * Par exemple, shift-claim y retire les candidatures encore en attente sur ce shift.
 */
final class OpenShiftClosed
{
    /**
     * @param array<string, mixed> $shift    Ligne de la table shifts après enregistrement
     * @param int|null             $closedBy Utilisateur qui a fermé le shift (null si inconnu)
     */
    public function __construct(
        public readonly array $shift,
        public readonly ?int $closedBy,
    ) {}

    /** Identifiant du shift fermé. */
    public function shiftId(): int
    {
        return (int) ($this->shift['id'] ?? 0);
    }
}
