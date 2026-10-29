<?php

declare(strict_types=1);

/**
 * Déclare dès le démarrage tous les noms du contrat de bundle (app/Contract/surface.php) comme alias de leur classe
 * du Core. Chargé par app/Support/helpers.php, donc par l'application comme par le socle de tests des bundles.
 *
 * Pourquoi pas un alias à la demande (un fichier par nom, chargé par l'autoloader) : PHP ne charge pas une classe
 * pour vérifier un type de paramètre. Un contrôleur de bundle typé `kintai\Contract\View\ViewRenderer` refuserait
 * alors l'instance de App\View\ViewRenderer reçue tant que rien n'aurait chargé l'alias.
 */
(static function (): void {
    foreach (require __DIR__ . '/surface.php' as $alias => $entry) {
        if (!class_exists($alias, false) && !interface_exists($alias, false) && !trait_exists($alias, false)) {
            class_alias($entry['target'], $alias);
        }
    }
})();
