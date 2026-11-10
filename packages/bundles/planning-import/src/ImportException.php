<?php

namespace Kintai\Bundles\PlanningImport;

use RuntimeException;

/**
 * Le fichier envoyé ne peut pas être lu comme un planning. Le message est technique : l'écran affiche son propre texte.
 */
class ImportException extends RuntimeException {}
