<?php

namespace Kintai\Contracts\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Périmètre autorisé d'un export, calculé par `ExportAccess` : les jeux de données (ceux des bundles aussi) ne reçoivent
 * que ce résultat et ne revérifient jamais les droits. Toute requête doit être bornée par `$storeIds`, par les employés
 * (`applyUsers`) et, quand le jeu de données a une période, par `$from` / `$to`.
 */
final readonly class ExportScope
{
    /**
     * @param  bool  $self  « Mes données » : l'acteur n'exporte que les siennes.
     * @param  list<int>  $storeIds  Magasins exportés (jamais vide).
     * @param  list<int>|null  $userIds  Employés exportés ; null = tous ceux des magasins.
     * @param  list<int>  $excludedUserIds  Employés que l'acteur ne peut pas voir (ex. un Owner, pour un gérant).
     * @param  CarbonImmutable  $from  Premier jour (local), borne incluse.
     * @param  CarbonImmutable  $to  Dernier jour (local), borne incluse.
     */
    public function __construct(
        public Authenticatable $actor,
        public bool $self,
        public array $storeIds,
        public ?array $userIds,
        public array $excludedUserIds,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Restreint une requête à ses employés (la requête est modifiée en place). `$column` : colonne de l'identifiant d'employé.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public function applyUsers(EloquentBuilder|QueryBuilder $query, string $column = 'user_id'): void
    {
        if ($this->userIds !== null) {
            $query->whereIn($column, $this->userIds);
        }

        if ($this->excludedUserIds !== []) {
            $query->whereNotIn($column, $this->excludedUserIds);
        }
    }
}
