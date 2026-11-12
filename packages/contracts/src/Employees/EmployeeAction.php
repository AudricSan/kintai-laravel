<?php

namespace Kintai\Contracts\Employees;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Une action qu'un bundle propose sur la ligne d'un employé (liste des employés, fiche) : un lien vers une page du
 * bundle qui reçoit l'employé en paramètre de requête `?user=<id>`. Objet valeur du contrat Core / bundles (depuis 1.5).
 */
final class EmployeeAction
{
    /**
     * @param  string  $key  Identifiant stable et unique.
     * @param  string  $label  Clé de traduction, traduite à l'affichage.
     * @param  string  $route  Nom de la route cible.
     * @param  int  $order  Ordre d'affichage (croissant).
     * @param  Closure(Authenticatable, int): bool  $visible  Reçoit la personne qui regarde et l'identifiant de l'employé.
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

    public function isVisibleTo(Authenticatable $viewer, int $employeeId): bool
    {
        return (bool) ($this->visible)($viewer, $employeeId);
    }

    /**
     * Adresse du lien pour un employé.
     */
    public function url(int $employeeId): string
    {
        return route($this->route, ['user' => $employeeId]);
    }
}
