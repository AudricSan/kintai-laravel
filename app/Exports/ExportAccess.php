<?php

namespace App\Exports;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Décide ce qu'un utilisateur peut exporter et en fait un périmètre sûr (`ExportScope`). C'est ici, et nulle part
 * ailleurs, que les droits sont vérifiés : les jeux de données, ceux des bundles compris, ne reçoivent que le résultat.
 *
 * - exporter les données des autres exige la permission du jeu de données dans chaque magasin exporté (rôle de portée
 *   magasin ou globale), et les magasins inactifs sont écartés ;
 * - « mes données » ne demande rien, mais seulement pour les jeux qui le permettent (`selfService`) ;
 * - les employés demandés sont toujours recoupés avec les membres des magasins autorisés, un identifiant glissé dans la
 *   requête ne donne jamais accès à quelqu'un d'un autre magasin ; un Owner reste invisible aux autres acteurs.
 */
class ExportAccess
{
    /** Période maximale exportable, en jours. */
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ExportDatasetRegistry $registry,
    ) {}

    /**
     * Magasins actifs où l'utilisateur détient la permission.
     *
     * @return list<int>
     */
    public function permittedStoreIds(User $user, string $permission): array
    {
        $allowed = $this->authorizer->storeIdsFor($user, $permission);

        if ($allowed === []) {
            return [];
        }

        return Store::query()
            ->where('is_active', true)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Magasins dont l'utilisateur est membre.
     *
     * @return list<int>
     */
    public function ownStoreIds(User $user): array
    {
        return DB::table('store_user')
            ->join('stores', 'stores.id', '=', 'store_user.store_id')
            ->whereNull('stores.deleted_at')
            ->where('store_user.user_id', $user->id)
            ->pluck('store_user.store_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Jeux de données que l'utilisateur peut exporter, avec les magasins permis et la possibilité d'exporter ses données.
     *
     * @return array<string, array{dataset: ExportDataset, stores: list<int>, self: bool}>
     */
    public function available(User $user): array
    {
        $own = $this->ownStoreIds($user);
        $available = [];

        foreach ($this->registry->all() as $key => $dataset) {
            $stores = $this->permittedStoreIds($user, $dataset->permission());
            $self = $dataset->selfService() && $own !== [];

            if ($stores !== [] || $self) {
                $available[$key] = ['dataset' => $dataset, 'stores' => $stores, 'self' => $self];
            }
        }

        return $available;
    }

    /**
     * Employés des magasins donnés que l'acteur peut voir, par nom.
     *
     * @param  list<int>  $storeIds
     * @return list<array{id: int, name: string}>
     */
    public function employees(User $actor, array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        $query = User::query()
            ->whereIn('id', DB::table('store_user')->whereIn('store_id', $storeIds)->select('user_id'))
            ->orderBy('name')
            ->orderBy('id');

        $excluded = $this->excludedUserIds($actor);
        if ($excluded !== []) {
            $query->whereNotIn('id', $excluded);
        }

        return $query->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all();
    }

    /**
     * Périmètre autorisé d'un export.
     *
     * @param  list<int>  $storeIds  Magasins demandés ; vide = tous ceux qui sont permis.
     * @param  list<int>  $userIds  Employés demandés ; vide = tous ceux que l'acteur peut voir.
     * @param  string|null  $from  Premier jour « AAAA-MM-JJ » ; ignoré (jour courant) sans période.
     *
     * @throws AuthorizationException si le périmètre demandé n'est pas permis
     * @throws ValidationException si la période est invalide
     */
    public function scope(User $actor, ExportDataset $dataset, bool $mine, array $storeIds, array $userIds, ?string $from, ?string $to): ExportScope
    {
        [$start, $end] = $dataset->hasPeriod() ? $this->period($from, $to) : [CarbonImmutable::today(), CarbonImmutable::today()];

        if ($mine) {
            $own = $this->ownStoreIds($actor);

            if (! $dataset->selfService() || $own === []) {
                throw new AuthorizationException;
            }

            return new ExportScope($actor, true, $own, [$actor->id], [], $start, $end);
        }

        $permitted = $this->permittedStoreIds($actor, $dataset->permission());
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        $stores = $storeIds === [] ? $permitted : $storeIds;

        if ($stores === [] || array_diff($stores, $permitted) !== []) {
            throw new AuthorizationException;
        }

        if (! $dataset->perEmployee()) {
            return new ExportScope($actor, false, $stores, null, [], $start, $end);
        }

        $excluded = $this->excludedUserIds($actor);
        $requested = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id): bool => $id > 0)));
        $members = $requested === [] ? null : $this->memberIds($stores, $requested);

        return new ExportScope($actor, false, $stores, $members, $excluded, $start, $end);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function period(?string $from, ?string $to): array
    {
        $start = $this->day($from);
        $end = $this->day($to);

        if ($start === null || $end === null || $end->lessThan($start) || $start->diffInDays($end) > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'from' => __('Choose a valid period of at most :days days.', ['days' => self::MAX_DAYS]),
            ]);
        }

        return [$start, $end];
    }

    private function day(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * Owners, invisibles pour qui ne l'est pas.
     *
     * @return list<int>
     */
    private function excludedUserIds(User $actor): array
    {
        return $this->authorizer->isOwner($actor) ? [] : $this->authorizer->ownerIds();
    }

    /**
     * Recoupe les employés demandés avec les membres des magasins autorisés.
     *
     * @param  list<int>  $storeIds
     * @param  list<int>  $requested
     * @return list<int>
     */
    private function memberIds(array $storeIds, array $requested): array
    {
        return DB::table('store_user')
            ->whereIn('store_id', $storeIds)
            ->whereIn('user_id', $requested)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
