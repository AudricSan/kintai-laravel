<?php

namespace App\Update;

use RuntimeException;

/**
 * Échec d'une étape de la mise à jour (liste des releases, téléchargement, contrôle de l'archive). Le message est destiné à l'utilisateur.
 */
class UpdateException extends RuntimeException {}
