<?php

namespace App\Exports;

/**
 * Un jeu de données exportable. Le Core enregistre les siens dans `ExportDatasetRegistry` (voir `CoreExportDatasets`) ;
 * les bundles y ajoutent les leurs depuis leur service provider :
 *
 *     $this->app->make(ExportDatasetRegistry::class)->register(new TimeOffExport);
 *
 * Les droits ne se vérifient pas ici : `ExportAccess` décide de l'accès (permission du jeu de données par magasin,
 * « mes données ») et ne transmet qu'un `ExportScope`. Une implémentation doit :
 *
 * - borner TOUTES ses requêtes par `$scope->storeIds`, `$scope->applyUsers()` et, si `hasPeriod()`, `$scope->from/to` ;
 * - renvoyer des lignes indexées par la clé des colonnes (une clé absente vaut null, une clé en trop est ignorée).
 *
 * Étendre `AbstractExportDataset` couvre le cas d'une requête unique.
 */
interface ExportDataset
{
    public function key(): string;

    /** Libellé affiché (clé de traduction). */
    public function label(): string;

    /** Permission exigée, par magasin, pour exporter les données des autres. */
    public function permission(): string;

    /** Chacun peut exporter ses propres lignes sans la permission. */
    public function selfService(): bool;

    /** Les lignes se rattachent à un employé : le filtre « employés » s'applique. */
    public function perEmployee(): bool;

    /** Les lignes se rattachent à des jours : le filtre « période » s'applique. */
    public function hasPeriod(): bool;

    /** @return list<ExportColumn> */
    public function columns(): array;

    /** @return list<string> Parmi `ExportWriter::FORMATS`. */
    public function formats(): array;

    /** Nombre de lignes du périmètre, sans les lire (limite de l'export). */
    public function count(ExportScope $scope): int;

    /**
     * @return iterable<array<string, scalar|null>>
     */
    public function rows(ExportScope $scope): iterable;
}
