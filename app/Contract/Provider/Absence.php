<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Absence d'un employé (congé, maladie…) renvoyée par un AbsenceProvider (contrat 1.10).
 *
 * Le Core s'en sert pour signaler un shift posé pendant une absence approuvée, afficher les absences dans le planning,
 * les exporter dans le flux iCal et calculer les statistiques d'absentéisme. Dates au format Y-m-d, fin incluse.
 */
final class Absence
{
    public const APPROVED = 'approved';
    public const PENDING = 'pending';
    public const REFUSED = 'refused';

    /**
     * @param int    $id        Identifiant (unique pour le fournisseur)
     * @param int    $userId    Employé absent
     * @param int    $storeId   Magasin concerné
     * @param string $startDate Premier jour (Y-m-d)
     * @param string $endDate   Dernier jour inclus (Y-m-d)
     * @param string $type      Type (ex. « vacation », « sick »), affiché tel quel ou traduit par le Core
     * @param string $status    APPROVED, PENDING, REFUSED ou un statut propre au bundle
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $storeId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $type = 'other',
        public readonly string $status = self::APPROVED,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1 || $endDate < $startDate) {
            throw new \InvalidArgumentException("Absence #{$id} : dates invalides ({$startDate} → {$endDate}).");
        }
    }

    /** Représentation sous forme de ligne, aux noms de colonnes historiques des congés (timeoff_requests). */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'store_id'   => $this->storeId,
            'start_date' => $this->startDate,
            'end_date'   => $this->endDate,
            'type'       => $this->type,
            'status'     => $this->status,
        ];
    }
}
