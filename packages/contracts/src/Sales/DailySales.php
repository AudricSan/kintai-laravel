<?php

namespace Kintai\Contracts\Sales;

use InvalidArgumentException;

/**
 * Les ventes d'un magasin pour un jour, renvoyées par un `DailySalesProvider` : chiffre d'affaires, coût de main-d'œuvre
 * saisi, nombre de clients et pertes, en valeurs du jour.
 */
final readonly class DailySales
{
    /**
     * @param  string  $date  Jour local (AAAA-MM-JJ).
     * @param  string  $status  Statut de la saisie du jour (ex. draft, submitted, validated).
     * @param  float  $sales  Chiffre d'affaires du jour.
     * @param  float  $laborCost  Coût de main-d'œuvre saisi pour le jour.
     * @param  int  $customers  Nombre de clients du jour.
     * @param  float  $waste  Pertes du jour.
     */
    public function __construct(
        public string $date,
        public string $status,
        public float $sales,
        public float $laborCost = 0.0,
        public int $customers = 0,
        public float $waste = 0.0,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("DailySales: invalid date [{$date}].");
        }
    }
}
