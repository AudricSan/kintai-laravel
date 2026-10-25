<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/**
 * Validations des feuilles de temps, une par magasin et semaine (voir TimesheetService). Les totaux sont rendus
 * décodés (minutes prévues et pointées par employé), jamais en JSON.
 */
interface TimesheetApprovalRepositoryInterface
{
    /** @return array{id: int, store_id: int, week_start: string, approved_by: ?int, approved_at: string, fingerprint: string, totals: array<int|string, mixed>}|null */
    public function find(int $storeId, string $weekStart): ?array;

    /**
     * Valide la semaine (remplace une validation précédente de la même semaine).
     *
     * @param array<int, array<string, int>> $totals
     * @return array{id: int, store_id: int, week_start: string, approved_by: ?int, approved_at: string, fingerprint: string, totals: array<int|string, mixed>}
     */
    public function approve(int $storeId, string $weekStart, ?int $approvedBy, string $fingerprint, array $totals): array;

    /** Annule la validation de la semaine ; renvoie le nombre de lignes supprimées. */
    public function reopen(int $storeId, string $weekStart): int;
}
