<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Widget de tableau de bord fourni par un bundle (point d'extension `Bundle::dashboardWidgets()`, contrat 1.4).
 *
 * Le Core le propose dans le panneau « Personnaliser » du tableau de bord visé (gérant ou employé) et l'affiche après
 * ses propres widgets quand l'utilisateur l'a activé (tous le sont par défaut), qu'il a la permission `permission`
 * et que la fonctionnalité `storeFeature` est active. Le Core appelle `data` puis rend la vue `view` (vue namespacée
 * du bundle, ex. « notebook::widgets.dashboard ») avec le tableau retourné ; une exception dans l'un ou l'autre
 * retire le widget et est journalisée, sans casser la page.
 */
final class DashboardWidget
{
    public const MANAGER = 'manager';
    public const EMPLOYEE = 'employee';

    /**
     * @param string        $key          Identifiant unique sur ce tableau de bord (préférences de l'utilisateur)
     * @param string        $audience     MANAGER (tableau de bord gérant) ou EMPLOYEE (tableau de bord employé)
     * @param string        $label        Clé de traduction du nom affiché dans le panneau « Personnaliser »
     * @param string        $view         Vue rendue (namespacée, ex. « notebook::widgets.dashboard »)
     * @param \Closure|null $data         fn(array $contexte): array — variables de la vue. Le contexte contient user
     *                                    (utilisateur connecté), store_ids (magasins visibles : ceux qu'il gère, ou les
     *                                    siens côté employé), managed_store_ids (null = tous, côté gérant), stores_map
     *                                    (id => nom) et today (Y-m-d)
     * @param string|null   $permission   Permission requise (ex. « notebook.view »)
     * @param string|null   $storeFeature Fonctionnalité par magasin requise (ex. « notes »)
     * @param int           $order        Ordre parmi les widgets de bundles
     * @param int|null      $defaultWidth Largeur par défaut en colonnes d'une grille de 12 (contrat 1.26) ; null = pleine
     *                                    largeur, comme avant
     * @param int           $defaultHeight Hauteur minimale par défaut en lignes (1 à 6) ; 1 = hauteur du contenu
     * @param int           $minWidth     Largeur minimale en colonnes que l'utilisateur peut donner au widget
     * @param int           $maxWidth     Largeur maximale en colonnes
     * @param int           $refreshInterval Rafraîchissement automatique en secondes (0 = jamais ; minimum 15 sinon)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $audience,
        public readonly string $label,
        public readonly string $view,
        public readonly ?\Closure $data = null,
        public readonly ?string $permission = null,
        public readonly ?string $storeFeature = null,
        public readonly int $order = 100,
        public readonly ?int $defaultWidth = null,
        public readonly int $defaultHeight = 1,
        public readonly int $minWidth = 1,
        public readonly int $maxWidth = 12,
        public readonly int $refreshInterval = 0,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("DashboardWidget : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        if (!in_array($audience, [self::MANAGER, self::EMPLOYEE], true)) {
            throw new \InvalidArgumentException("DashboardWidget « {$key} » : public inconnu « {$audience} ».");
        }
        if ($minWidth < 1 || $maxWidth > 12 || $minWidth > $maxWidth) {
            throw new \InvalidArgumentException("DashboardWidget « {$key} » : largeurs min/max invalides ({$minWidth}–{$maxWidth}, attendu 1–12).");
        }
        if ($defaultWidth !== null && ($defaultWidth < $minWidth || $defaultWidth > $maxWidth)) {
            throw new \InvalidArgumentException("DashboardWidget « {$key} » : largeur par défaut hors des bornes {$minWidth}–{$maxWidth}.");
        }
        if ($defaultHeight < 1 || $defaultHeight > 6) {
            throw new \InvalidArgumentException("DashboardWidget « {$key} » : hauteur par défaut invalide ({$defaultHeight}, attendu 1–6).");
        }
        if ($refreshInterval !== 0 && $refreshInterval < 15) {
            throw new \InvalidArgumentException("DashboardWidget « {$key} » : rafraîchissement minimum 15 secondes (0 = jamais).");
        }
    }
}
