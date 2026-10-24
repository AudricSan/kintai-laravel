<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Fournit les lignes d'un jeu de données exportable (contrat 1.30, voir ExportDataset). Le Core a déjà vérifié les droits :
 * le fournisseur ne lit que les magasins et personnes de l'ExportQuery.
 *
 * Chaque ligne est un tableau clé => valeur dont les clés sont celles déclarées dans ExportDataset::$columns ; les valeurs
 * sont des scalaires ou null (le Core les met en forme pour le CSV, le JSON et le classeur). Une clé manquante vaut null,
 * une clé en trop est ignorée.
 */
interface ExportRowsProvider
{
    /** @return iterable<array<string, scalar|null>> */
    public function rows(ExportQuery $query): iterable;
}
