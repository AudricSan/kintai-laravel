<?php

namespace Kintai\Contracts\Employees;

/**
 * Activation d'un compte, par le Core : un bundle qui doit désactiver ou réactiver un compte (départ d'un employé à une
 * date, par exemple) passe par ce service au lieu d'écrire dans la table des utilisateurs. Le Core applique ses
 * garde-fous (on ne désactive jamais le dernier Owner actif) et révoque les sessions et jetons d'un compte désactivé.
 * L'événement `EmployeeUpdated` est émis quand l'état change.
 *
 * Service du Core uniquement : les bundles l'appellent, ils ne l'implémentent pas.
 */
interface AccountActivation
{
    /**
     * Désactive le compte.
     *
     * @param  int|null  $actorId  Utilisateur qui agit, ou `null` pour une action automatique (tâche planifiée). Un acteur
     *                             doit pouvoir modifier l'employé (droit `employees.update` sur l'un de ses magasins) et ne peut pas
     *                             désactiver son propre compte, sauf s'il est Owner.
     * @return bool `true` si le compte a été désactivé, `false` s'il l'était déjà ou n'existe pas.
     *
     * Lève une `AuthorizationException` (exception de Laravel, hors du contrat) si un garde-fou refuse : droit manquant,
     * compte de l'acteur lui-même, dernier Owner actif. Rien n'est alors modifié.
     */
    public function deactivate(int $userId, ?int $actorId = null): bool;

    /**
     * Réactive le compte.
     *
     * @return bool `true` si le compte a été réactivé, `false` s'il était déjà actif ou n'existe pas.
     */
    public function reactivate(int $userId): bool;
}
