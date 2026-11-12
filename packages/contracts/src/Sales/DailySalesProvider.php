<?php

namespace Kintai\Contracts\Sales;

use Carbon\CarbonInterface;

/**
 * Ventes journalières d'un magasin. Le Core n'a pas de ventes : le bundle daily-report remplace l'implémentation liée dans
 * le conteneur (vide par défaut). Un bundle qui a besoin des ventes (rentabilité, pré-remplissage…) injecte cette interface
 * sans connaître les tables du fournisseur.
 */
interface DailySalesProvider
{
    /**
     * Ventes du magasin pour chaque jour de la période (jours locaux, bornes incluses), en valeurs du jour, triées par date.
     * Un fournisseur dont la saisie est cumulative la ramène lui-même à des valeurs quotidiennes.
     *
     * @param  list<string>|null  $statuses  Ne garder que les jours à ces statuts (ex. ['validated', 'submitted']) ; null = tous.
     * @return list<DailySales>
     */
    public function dailySales(int $storeId, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null): array;

    /**
     * Un fournisseur de ventes est-il actif ? Faux pour l'implémentation vide du Core : une page de rentabilité n'a alors
     * rien à montrer.
     */
    public function available(): bool;
}
