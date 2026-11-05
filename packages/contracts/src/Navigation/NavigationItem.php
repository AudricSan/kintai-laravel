<?php

namespace Kintai\Contracts\Navigation;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Request;

/**
 * Une entrée du menu de l'application. Objet valeur du contrat Core / bundles.
 */
final class NavigationItem
{
    /**
     * @param  string  $key  Identifiant stable, utilisé par les préférences de navigation.
     * @param  string  $label  Clé de traduction, traduite à l'affichage.
     * @param  string  $route  Nom de la route.
     * @param  string  $icon  Nom d'icône Flux.
     * @param  string  $section  Une des clés de `NavigationRegistry::SECTIONS`.
     * @param  string|null  $permission  Capacité du Gate : sans elle, l'entrée n'est pas affichée.
     * @param  (Closure(Authenticatable): bool)|null  $visible  Condition supplémentaire ; absente : toujours visible.
     * @param  bool  $hideable  L'utilisateur peut la masquer dans ses préférences.
     * @param  list<string>  $current  Motifs de noms de routes pour lesquels l'entrée est la page courante
     *                                 (par défaut : la route de l'entrée).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $route,
        public readonly string $icon,
        public readonly string $section,
        public readonly int $order = 100,
        public readonly ?string $permission = null,
        private readonly ?Closure $visible = null,
        public readonly bool $hideable = true,
        public readonly array $current = [],
    ) {}

    public function isVisibleTo(Authenticatable $user): bool
    {
        if ($this->permission !== null && ! Gate::forUser($user)->allows($this->permission)) {
            return false;
        }

        return $this->visible === null || (bool) ($this->visible)($user);
    }

    public function isCurrent(): bool
    {
        return Request::routeIs(...($this->current === [] ? [$this->route] : $this->current));
    }
}
