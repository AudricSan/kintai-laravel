<?php

namespace App\Backups;

use RuntimeException;

/**
 * Erreur de sauvegarde ou de restauration dont le message est sûr à montrer à l'Owner (aucun chemin, aucune requête,
 * aucun identifiant de base). Les erreurs techniques sont signalées par `report()` et remplacées par un message neutre.
 */
class BackupException extends RuntimeException {}
