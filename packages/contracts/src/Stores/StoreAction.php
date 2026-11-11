<?php

namespace Kintai\Contracts\Stores;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Une action qu'un bundle propose sur la ligne d'un magasin dans la liste des magasins : un lien vers une page du bundle
 * qui reçoit le magasin en paramètre de requête `?store=<id>`. Objet valeur du contrat Core / bundles (depuis 1.7).
 */
final class StoreAction
{
    /**
     * @param  string  $key  Identifiant stable et unique.
     * @param  string  $label  Clé de traduction, traduite à l'affichage.
     * @param  string  $route  Nom de la route cible.
     * @param  int  $order  Ordre d'affichage (croissant).
     * @param  Closure(Authenticatable, int): bool  $visible  Reçoit la personne qui regarde et l'identifiant du magasin.
     *                                                        Un lien n'est qu'un raccourci : la page cible revérifie
     *                                                        ses droits.
     * @param  string|null  $icon  Nom d'icône Flux, facultatif.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $route,
        public readonly int $order,
        private readonly Closure $visible,
        public readonly ?string $icon = null,
    ) {}

    public function isVisibleTo(Authenticatable $viewer, int $storeId): bool
    {
        return (bool) ($this->visible)($viewer, $storeId);
    }

    /**
     * Adresse du lien pour un magasin.
     */
    public function url(int $storeId): string
    {
        return route($this->route, ['store' => $storeId]);
    }
}
