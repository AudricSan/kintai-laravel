<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Ventes d'un magasin pour un jour, renvoyées par un SalesProvider (contrat 1.14) : chiffre d'affaires, coût de
 * main-d'œuvre saisi, nombre de clients et pertes, en valeurs du jour.
 */
final class DailySales
{
    /**
     * @param string $date      Jour (Y-m-d)
     * @param string $status    Statut de la saisie du jour (ex. draft, submitted, validated)
     * @param float  $sales     Chiffre d'affaires du jour
     * @param float  $laborCost Coût de main-d'œuvre saisi pour le jour
     * @param int    $customers Nombre de clients du jour
     * @param float  $waste     Pertes du jour
     */
    public function __construct(
        public readonly string $date,
        public readonly string $status,
        public readonly float $sales,
        public readonly float $laborCost = 0.0,
        public readonly int $customers = 0,
        public readonly float $waste = 0.0,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException("DailySales : date invalide « {$date} ».");
        }
    }

    /** Représentation sous forme de ligne, aux noms de colonnes historiques des rapports journaliers. */
    public function toArray(): array
    {
        return [
            'report_date'    => $this->date,
            'status'         => $this->status,
            'sales_total'    => $this->sales,
            'labor_cost'     => $this->laborCost,
            'customer_count' => $this->customers,
            'waste_total'    => $this->waste,
        ];
    }
}
