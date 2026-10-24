<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Entrée de navigation fournie par un bundle (point d'extension `Bundle::navigation()`, contrat 1.1).
 *
 * Le Core la place dans la section demandée de la barre du haut, et dans la barre mobile si elle y est éligible ;
 * l'utilisateur peut la masquer depuis ses préférences de navigation (clé `key`). Elle n'est affichée que si :
 * - le rôle courant a une route (gérant ou employé) et la permission déclarée sur cette route ;
 * - la fonctionnalité `storeFeature`, si elle est donnée, est active pour les magasins de l'utilisateur.
 */
final class NavItem
{
    /** Sections de la barre du haut ; MAIN = lien direct, hors menu déroulant. */
    public const MAIN = 'main';
    public const PLANNING = 'planning';
    public const HR = 'hr';
    public const REQUESTS = 'requests';
    public const REPORTS = 'statistics';
    public const SYSTEM = 'system';

    public const SECTIONS = [self::MAIN, self::PLANNING, self::HR, self::REQUESTS, self::REPORTS, self::SYSTEM];

    /**
     * @param string                           $key            Identifiant unique (préférences de navigation), ex. « timeoff »
     * @param string                           $section        Une des constantes de section
     * @param string                           $label          Clé de traduction du libellé (vue gérant)
     * @param string|null                      $managerRoute   Nom de route côté gérant, ou null s'il n'y en a pas
     * @param string|null                      $employeeRoute  Nom de route côté employé, ou null s'il n'y en a pas
     * @param string                           $icon           Clé d'icône du Core (calendar, clock, leaf, arrows…)
     * @param string|null                      $employeeLabel  Clé de traduction côté employé (défaut : $label)
     * @param list<string>                     $activePaths    Préfixes de chemin qui rendent l'entrée active ; « *fragment »
     *                                                         = le chemin contient le fragment
     * @param string|null                      $storeFeature   Fonctionnalité par magasin requise (ex. « timeoff »)
     * @param string|null                      $badge          Nom de la variable de vue contenant un compteur à afficher
     * @param int                              $order          Ordre dans la section (les entrées du Core vont de 10 en 10)
     * @param bool                             $mobile         Éligible à la barre de navigation mobile
     * @param \Closure|null                    $links          Plusieurs liens au lieu d'un seul : fn(array $vue, bool $gérant):
     *                                                         list<array{href: string, label: string, active: bool}> ;
     *                                                         ex. un lien par magasin. La fonction décide seule de ce qui est
     *                                                         visible ; $vue contient route_visible (fn(string $route): bool),
     *                                                         route_url (fn(string $route): string),
     *                                                         path, isOwner, BASE_URL et les autres variables de la page ;
     *                                                         aucun lien = entrée masquée.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $section,
        public readonly string $label,
        public readonly ?string $managerRoute,
        public readonly ?string $employeeRoute,
        public readonly string $icon = 'dot',
        public readonly ?string $employeeLabel = null,
        public readonly array $activePaths = [],
        public readonly ?string $storeFeature = null,
        public readonly ?string $badge = null,
        public readonly int $order = 100,
        public readonly bool $mobile = false,
        public readonly ?\Closure $links = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("NavItem : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException("NavItem « {$key} » : section inconnue « {$section} ».");
        }
        if ($managerRoute === null && $employeeRoute === null) {
            throw new \InvalidArgumentException("NavItem « {$key} » : au moins une route (gérant ou employé) est requise.");
        }
    }

    /** Route pour le rôle courant, ou null si l'entrée n'existe pas pour ce rôle. */
    public function routeFor(bool $isManager): ?string
    {
        return $isManager ? $this->managerRoute : $this->employeeRoute;
    }

    public function labelFor(bool $isManager): string
    {
        return $isManager ? $this->label : ($this->employeeLabel ?? $this->label);
    }

    /**
     * Liens à afficher quand l'entrée en fournit plusieurs ($links), ou null pour le lien unique habituel.
     *
     * @param array<string, mixed> $view Variables de la vue (magasins de l'utilisateur, compteurs…)
     * @return list<array{href: string, label: string, active: bool}>|null
     */
    public function linksFor(array $view, bool $isManager): ?array
    {
        if ($this->links === null) {
            return null;
        }
        $links = [];
        foreach ((array) ($this->links)($view, $isManager) as $link) {
            if (is_array($link) && is_string($link['href'] ?? null) && is_string($link['label'] ?? null)) {
                $links[] = ['href' => $link['href'], 'label' => $link['label'], 'active' => !empty($link['active'])];
            }
        }
        return $links;
    }

    public function isActive(string $path): bool
    {
        foreach ($this->activePaths as $pattern) {
            // « *fragment » : le chemin contient le fragment (ex. « */reports/hiring » couvre aussi
            // /admin/stores/3/reports/hiring) ; sinon, préfixe.
            $matches = str_starts_with($pattern, '*')
                ? $pattern !== '*' && str_contains($path, substr($pattern, 1))
                : $pattern !== '' && str_starts_with($path, $pattern);
            if ($matches) {
                return true;
            }
        }
        return false;
    }
}
