<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Fournisseur de ventes journalières (contrat 1.14), implémenté par un bundle (daily-report) et déclaré par
 * `Bundle::salesProviders()`.
 *
 * Le Core l'interroge pour la rentabilité d'un magasin, et les bundles le lisent par `kintai\Contract\Services\Sales`
 * (pré-remplissage du rapport de salaire), sans connaître les tables du bundle. Les ventes de tous les fournisseurs
 * déclarés sont réunies ; un fournisseur en échec est ignoré et journalisé.
 */
interface SalesProvider
{
    /**
     * Ventes du magasin pour chaque jour de la période (bornes incluses, format Y-m-d), en valeurs du jour : un
     * fournisseur dont la saisie est cumulative les ramène lui-même à des valeurs quotidiennes.
     *
     * @param list<string>|null $statuses Ne garder que les jours à ces statuts (ex. ['validated', 'submitted']), avant
     *                                    la conversion en valeurs du jour ; null = tous
     * @return list<DailySales>
     */
    public function dailySales(int $storeId, string $from, string $to, ?array $statuses = null): array;
}
