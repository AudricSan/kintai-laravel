<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Fournisseur d'absences (contrat 1.10), implémenté par un bundle et déclaré par `Bundle::absenceProviders()`.
 *
 * Le Core l'interroge pour le planning (conflit shift/absence, affichage), l'export iCal et les statistiques, sans
 * connaître les tables du bundle. Les absences de tous les fournisseurs déclarés sont additionnées ; un fournisseur
 * en échec est ignoré et journalisé.
 */
interface AbsenceProvider
{
    /**
     * Absences de tous statuts, filtrées par employés et/ou magasins (null = pas de filtre). Le Core filtre lui-même
     * les dates et les statuts dont il a besoin.
     *
     * @param list<int>|null $userIds
     * @param list<int>|null $storeIds
     * @return list<Absence>
     */
    public function absences(?array $userIds, ?array $storeIds): array;
}
