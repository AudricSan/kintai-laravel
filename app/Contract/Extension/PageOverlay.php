<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Fenêtre affichée par-dessus les pages d'un utilisateur connecté (point d'extension `Bundle::pageOverlays()`,
 * contrat 1.27) : une question à poser, une validation à faire… Le Core la rend dans une zone dédiée du layout, dans un
 * garde (une exception retire la fenêtre et est journalisée, sans casser la page), avec `role="dialog"`, le focus
 * piégé et Échap si elle est fermable.
 *
 * Règles du Core : une seule fenêtre à la fois (la plus prioritaire), jamais sur la connexion, l'impression ni les pages
 * d'édition d'un shift (pour ne jamais empêcher de corriger). Chaque action de la fenêtre passe par les routes du
 * bundle, qui contrôlent la permission et la portée côté serveur : la fenêtre n'est qu'une présentation.
 *
 * Deux présentations : `dialog` (défaut) bloque la page sous un fond et piège le focus ; `bar` est une barre discrète en bas
 * de page qui ne bloque rien (rappel permanent, par exemple « vous modifiez le planning de demain — revenir à la
 * validation »).
 *
 * La vue reçoit `data` plus `overlay_key` et `overlay_title_id` : l'élément qui titre la fenêtre doit porter cet id
 * (`aria-labelledby`). Tout élément `data-overlay-close` la ferme (jusqu'à la page suivante, sauf `oncePerSession`).
 */
final class PageOverlay
{
    public const DIALOG = 'dialog';
    public const BAR = 'bar';

    /**
     * @param string        $key          Identifiant unique (minuscules, chiffres, _)
     * @param string        $view         Vue namespacée du bundle (ex. « planning-check::overlay »), contenu de la fenêtre
     * @param \Closure|null $visibleWhen  fn(array $contexte): bool — la fenêtre doit-elle s'afficher ? Évaluée côté
     *                                    serveur à chaque page. Le contexte contient user, now (DateTimeImmutable),
     *                                    today (Y-m-d), path (chemin demandé), store_ids (magasins de l'utilisateur) et
     *                                    can(permission, ?storeId) : bool
     * @param \Closure|null $data         fn(array $contexte): array — variables de la vue (appelée seulement si visible)
     * @param string|null   $permission   Permission requise (sur n'importe quel magasin ou en portée globale)
     * @param string|null   $storeFeature Fonctionnalité par magasin requise
     * @param int           $priority     Plus petit = plus prioritaire quand plusieurs fenêtres sont visibles
     * @param bool          $dismissible  Échap et clic hors de la fenêtre la ferment (jusqu'à la page suivante)
     * @param bool          $oncePerSession Une fois fermée, ne revient pas avant la prochaine session du navigateur
     * @param string        $placement   `dialog` (fenêtre modale) ou `bar` (barre en bas de page, non bloquante)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $view,
        public readonly ?\Closure $visibleWhen = null,
        public readonly ?\Closure $data = null,
        public readonly ?string $permission = null,
        public readonly ?string $storeFeature = null,
        public readonly int $priority = 100,
        public readonly bool $dismissible = true,
        public readonly bool $oncePerSession = false,
        public readonly string $placement = self::DIALOG,
    ) {
        if (!in_array($placement, [self::DIALOG, self::BAR], true)) {
            throw new \InvalidArgumentException("PageOverlay « {$key} » : présentation inconnue « {$placement} ».");
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("PageOverlay : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        if ($view === '') {
            throw new \InvalidArgumentException("PageOverlay « {$key} » : vue obligatoire.");
        }
    }
}
