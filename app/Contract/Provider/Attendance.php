<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Pointage (arrivée, et départ s'il a eu lieu) renvoyé par un AttendanceProvider (contrat 1.11).
 *
 * Le Core s'en sert pour les pointages en cours et les pointages oubliés du tableau de bord, et pour compter les
 * retards (arrivée comparée au début du shift prévu).
 */
final class Attendance
{
    /**
     * @param int         $id        Identifiant (unique pour le fournisseur)
     * @param int         $userId    Employé
     * @param int         $storeId   Magasin
     * @param string      $shiftDate Jour travaillé (Y-m-d)
     * @param string      $clockIn   Arrivée (Y-m-d H:i:s)
     * @param string|null $clockOut  Départ (Y-m-d H:i:s), null tant que l'employé n'a pas pointé sa sortie
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $storeId,
        public readonly string $shiftDate,
        public readonly string $clockIn,
        public readonly ?string $clockOut = null,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $clockIn) !== 1) {
            throw new \InvalidArgumentException("Attendance #{$id} : date ou heure d'arrivée invalide.");
        }
    }

    /** Représentation sous forme de ligne, aux noms de colonnes historiques des pointages (timeclocks). */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->userId,
            'store_id'       => $this->storeId,
            'shift_date'     => $this->shiftDate,
            'clock_in_time'  => $this->clockIn,
            'clock_out_time' => $this->clockOut,
        ];
    }
}
