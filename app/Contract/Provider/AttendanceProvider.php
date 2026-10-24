<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Fournisseur de pointages (contrat 1.11), implémenté par un bundle et déclaré par `Bundle::attendanceProviders()`.
 *
 * Le Core l'interroge pour le tableau de bord (pointages en cours ou oubliés, retards) sans connaître les tables du
 * bundle. Les pointages de tous les fournisseurs déclarés sont additionnés ; un fournisseur en échec est ignoré et
 * journalisé.
 */
interface AttendanceProvider
{
    /**
     * Pointages filtrés par employés, magasins et période de jours travaillés (null = pas de filtre ; bornes
     * incluses, format Y-m-d).
     *
     * @param list<int>|null $userIds
     * @param list<int>|null $storeIds
     * @return list<Attendance>
     */
    public function attendance(?array $userIds, ?array $storeIds, ?string $from = null, ?string $to = null): array;
}
