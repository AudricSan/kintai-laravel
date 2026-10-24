<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

use kintai\Contract\Provider\ExportRowsProvider;

/**
 * Jeu de données exportable, fourni par un bundle (point d'extension `Bundle::exportDatasets()`, contrat 1.30) et proposé
 * dans la page « Exports » en JSON, CSV et classeur Excel.
 *
 * Le Core décide qui peut exporter quoi : l'utilisateur doit détenir `permission` dans chaque magasin exporté (rôle de
 * portée magasin ou globale). Si `selfService` est vrai, chacun peut aussi exporter ses propres lignes sans cette
 * permission. Le fournisseur ne reçoit qu'un périmètre déjà autorisé (ExportQuery) ; il ne doit rien lire d'autre.
 *
 * Les libellés sont des clés de traduction du bundle (fichier lang/ du bundle), pas du texte.
 */
final class ExportDataset
{
    /**
     * @param string                $key         Identifiant unique, préfixé par le slug du bundle (ex. « timeclock_entries »)
     * @param string                $label       Clé de traduction du nom du jeu de données
     * @param string                $permission  Permission exigée pour exporter les données des autres (ex. « timeclock.view »)
     * @param array<string, string> $columns     Colonnes dans l'ordre : clé de la ligne => clé de traduction de l'en-tête
     * @param ExportRowsProvider    $provider    Lit les lignes d'un périmètre autorisé
     * @param bool                  $selfService Chacun peut exporter ses propres lignes sans la permission
     * @param bool                  $perPerson   Les lignes portent sur des personnes (filtrables) ; faux pour un jeu par magasin
     * @param bool                  $hasPeriod   Les lignes dépendent d'une période (dates « du » et « au »)
     * @param int                   $order       Ordre dans la liste (les jeux du Core vont de 10 à 90)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $permission,
        public readonly array $columns,
        public readonly ExportRowsProvider $provider,
        public readonly bool $selfService = false,
        public readonly bool $perPerson = true,
        public readonly bool $hasPeriod = true,
        public readonly int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,50}$/', $key) !== 1) {
            throw new \InvalidArgumentException("ExportDataset : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $permission) !== 1) {
            throw new \InvalidArgumentException("ExportDataset « {$key} » : permission invalide « {$permission} ».");
        }
        if ($columns === []) {
            throw new \InvalidArgumentException("ExportDataset « {$key} » : aucune colonne.");
        }
        foreach ($columns as $column => $header) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,40}$/', $column) !== 1 || !is_string($header) || $header === '') {
                throw new \InvalidArgumentException("ExportDataset « {$key} » : colonne invalide.");
            }
        }
    }
}
