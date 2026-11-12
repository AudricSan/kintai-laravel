<?php

namespace App\Activity;

use App\Authorization\Authorizer;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Entrées du journal visibles par un utilisateur, avec les filtres de l'écran et de l'export.
 *
 * Restriction : qui n'a `stores.view` que sur certains magasins ne voit que les entrées de ces magasins ; les entrées
 * sans magasin (connexions, employés, rôles…) ne lui sont pas visibles. Owner et droits sans restriction : tout.
 */
class ActivityQuery
{
    /** @var list<string> */
    public const FILTERS = ['action', 'actor', 'store', 'from', 'to'];

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @param  array<string, mixed>  $filters  action (clé), actor (identifiant), store (identifiant), from / to (AAAA-MM-JJ, jour local)
     * @return Builder<ActivityEntry>
     */
    public function for(User $user, array $filters = []): Builder
    {
        $query = $this->visibleTo($user);

        $action = $this->text($filters['action'] ?? null);
        if ($action !== null) {
            $query->where('action', $action);
        }

        $actor = $this->id($filters['actor'] ?? null);
        if ($actor !== null) {
            $query->where('actor_id', $actor);
        }

        $store = $this->id($filters['store'] ?? null);
        if ($store !== null) {
            $query->where('store_id', $store);
        }

        $from = $this->day($filters['from'] ?? null);
        if ($from !== null) {
            $query->where('created_at', '>=', $from->startOfDay()->utc());
        }

        $to = $this->day($filters['to'] ?? null);
        if ($to !== null) {
            $query->where('created_at', '<=', $to->endOfDay()->utc());
        }

        return $query->latest('created_at')->latest('id');
    }

    /**
     * @return Builder<ActivityEntry>
     */
    public function visibleTo(User $user): Builder
    {
        $query = ActivityEntry::query();
        $storeIds = $this->authorizer->storeIdsFor($user, 'stores.view');

        if ($storeIds !== null) {
            $query->whereIn('store_id', $storeIds);
        }

        return $query;
    }

    /**
     * Magasins proposés dans le filtre.
     *
     * @return list<array{id: int, name: string}>
     */
    public function storeOptions(User $user): array
    {
        $storeIds = $this->authorizer->storeIdsFor($user, 'stores.view');

        return Store::query()
            ->when($storeIds !== null, fn (Builder $q) => $q->whereIn('id', $storeIds))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Store $store): array => ['id' => $store->id, 'name' => $store->name])
            ->all();
    }

    /**
     * Utilisateurs ayant au moins une entrée visible.
     *
     * @return list<array{id: int, name: string}>
     */
    public function actorOptions(User $user): array
    {
        return User::withTrashed()
            ->whereIn('id', $this->visibleTo($user)->whereNotNull('actor_id')->select('actor_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $actor): array => ['id' => $actor->id, 'name' => $actor->name])
            ->all();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function id(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    private function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('kintai.timezone')) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
