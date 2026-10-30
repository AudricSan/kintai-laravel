<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Availability;

/**
 * Détecte qu'on planifie quelqu'un un jour où il s'est déclaré non disponible (onglet « Disponibilités » du profil).
 *
 * Une disponibilité est une information, pas une contrainte : le contrôle ne bloque jamais, il permet au planning
 * de prévenir le manager, qui peut forcer l'horaire. Sans disponibilité enregistrée pour ce magasin (l'employé n'a
 * jamais rempli l'onglet), on ne sait rien et rien n'est signalé.
 */
final class AvailabilityGuard
{
    /**
     * Jour de la semaine (1 = lundi … 7 = dimanche, comme day_of_week) si l'employé est non disponible à cette date
     * dans ce magasin, sinon null.
     */
    public function unavailableWeekday(int $userId, int $storeId, string $shiftDate): ?int
    {
        $timestamp = strtotime($shiftDate);
        if ($userId <= 0 || $storeId <= 0 || $timestamp === false) {
            return null;
        }

        $weekday = (int) date('N', $timestamp);
        foreach (Availability::findByUserAndStore($userId, $storeId) as $row) {
            if ((int) ($row['day_of_week'] ?? 0) === $weekday && (int) ($row['is_available'] ?? 1) === 0) {
                return $weekday;
            }
        }

        return null;
    }
}
