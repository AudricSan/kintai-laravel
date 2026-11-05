<?php

namespace App\Backups;

/**
 * Exporte et réimporte la base de données dans un fichier unique, en PHP pur (sans `mysqldump` ni binaire externe).
 */
interface DatabaseDumper
{
    /** Famille de pilote écrite dans le manifeste : `sqlite` ou `mysql` (MariaDB inclus). */
    public function driver(): string;

    /** Nom du fichier de la base dans l'archive. */
    public function fileName(): string;

    /**
     * Écrit la base courante dans `$path`.
     *
     * @throws BackupException
     */
    public function dump(string $path): void;

    /**
     * Remplace la base courante par le contenu de `$path` (produit par `dump()`).
     *
     * @throws BackupException
     */
    public function restore(string $path): void;
}
