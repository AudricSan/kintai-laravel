<?php

namespace Kintai\Contracts\Stores;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Service du Core : les collègues d'un utilisateur et ce qu'il a le droit d'en voir. Un bundle (annuaire, messagerie…)
 * ne lit jamais les tables d'identité ni les fiches RH : il passe par ce service, qui applique les trois niveaux de
 * visibilité du Core.
 *
 * - Un collègue est un compte actif et non anonymisé, membre actif d'un magasin que l'utilisateur partage avec lui.
 *   L'Owner voit les membres de tous les magasins. Un compte sans magasin (un Owner par exemple) n'est jamais listé.
 * - Les coordonnées (e-mail, téléphone, mobile) ne sont fournies que si le collègue les a partagées, ou si
 *   l'utilisateur gère un magasin commun (droit `employees.view` sur ce magasin). Jamais d'autre donnée RH.
 * - Aucune donnée n'est fournie pour un magasin que l'utilisateur ne voit pas : les identifiants demandés sont toujours
 *   recoupés avec `stores()`.
 */
interface ColleagueDirectory
{
    /**
     * Magasins dont l'utilisateur voit l'équipe : ceux dont il est membre actif (tous pour l'Owner), non supprimés,
     * triés par nom. Avec `$feature`, seulement ceux où cette fonctionnalité de magasin est activée.
     *
     * @return array<int, string> identifiant => nom
     */
    public function stores(Authenticatable $viewer, ?string $feature = null): array;

    /**
     * Collègues de l'utilisateur dans les magasins demandés, triés par nom. Jamais l'utilisateur lui-même.
     *
     * @param  list<int>  $storeIds
     * @return list<Colleague>
     */
    public function colleagues(Authenticatable $viewer, array $storeIds): array;

    /**
     * Un collègue, ou l'utilisateur lui-même (toujours visible, avec toutes ses coordonnées) ; null s'il n'est pas un
     * collègue visible dans les magasins demandés.
     *
     * @param  list<int>  $storeIds
     */
    public function colleague(Authenticatable $viewer, int $userId, array $storeIds): ?Colleague;
}
