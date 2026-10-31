<?php

declare(strict_types=1);

namespace kintai\Contract;

/**
 * Contrat entre le Core et les bundles (voir docs/creating-a-bundle.md, « Contrat de bundle »).
 *
 * Tout ce qui vit sous kintai\Contract est la seule surface qu'un bundle, officiel ou tiers, a le droit
 * d'utiliser. Sa version est indépendante de celle du Core et suit le versionnage sémantique :
 * - mineure : ajout (classe, méthode optionnelle, point d'extension) ;
 * - majeure : suppression ou changement de signature, annoncé dans la section « Contrat de bundle » du CHANGELOG.
 *
 * Un bundle déclare la version qu'il requiert dans son bundle.json (« kintai_contract »: « ^1.0 ») ; le Core
 * refuse de l'installer, et ne le charge pas au démarrage, si la version fournie ne la satisfait pas.
 */
final class Contract
{
    /** Version du contrat fournie par ce Core. */
    public const VERSION = '1.1.0';

    /*
     * Historique :
     * - 1.0.0 : premier contrat de la version Laravel native. Les bundles sont des service providers Laravel (routes
     *   Route::, vues Blade, requêtes et réponses de Laravel) ; les anciens contrats 0.x/1.x/2.0 ne sont pas repris.
     */

    private function __construct() {}

    /** La version fournie par ce Core satisfait-elle la contrainte déclarée par un bundle ? */
    public static function satisfies(string $constraint): bool
    {
        return VersionConstraint::parse($constraint)?->allows(self::VERSION) ?? false;
    }
}
