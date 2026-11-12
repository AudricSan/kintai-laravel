<?php

namespace App\Install;

use RuntimeException;

/**
 * Échec attendu de l'installation (déjà installé, prérequis manquants, base injoignable, .env déjà configuré...).
 * Le message est destiné à l'utilisateur et déjà traduit.
 */
final class InstallFailure extends RuntimeException {}
