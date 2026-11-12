<?php

namespace App\Planning;

use App\Models\Availability;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Détecte qu'on planifie quelqu'un un jour où il s'est déclaré indisponible (section « Disponibilités » du profil).
 *
 * Une disponibilité est une information, pas une contrainte : le contrôle ne bloque jamais, il prévient le manager,
 * qui peut forcer. Sans ligne pour ce magasin (l'employé n'a rien rempli), on ne sait rien et rien n'est signalé.
 */
class AvailabilityGuard
{
    /**
     * Jour ISO (1 = lundi ... 7 = dimanche) si l'employé est indisponible à cette date dans ce magasin, sinon null.
     */
    public function unavailableWeekday(int $userId, int $storeId, DateTimeInterface|string $date): ?int
    {
        if ($userId <= 0 || $storeId <= 0) {
            return null;
        }

        try {
            $weekday = Availability::isoDayOf($date);
        } catch (Throwable) {
            return null;
        }

        $unavailable = Availability::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->where('day_of_week', $weekday)
            ->where('is_available', false)
            ->exists();

        return $unavailable ? $weekday : null;
    }
}
