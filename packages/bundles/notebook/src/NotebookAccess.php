<?php

namespace Kintai\Bundles\Notebook;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\Notebook\Models\NotebookEntry;

/**
 * Qui voit, écrit et gère quelles notes. Droits `notebook.view`, `notebook.create` et `notebook.manage`, avec la portée
 * magasin du Core (`Authorizer`) :
 *
 * - voir : les notes des magasins où l'on a `notebook.view` et où la fonctionnalité est activée, et les notes de toute
 *   l'organisation ;
 * - écrire une note de magasin : `notebook.create` sur ce magasin ; une note de toute l'organisation : `notebook.create`
 *   sans restriction de magasin ;
 * - modifier ou supprimer : l'auteur (tant qu'il peut écrire à cet endroit) ou `notebook.manage` sur le magasin de la
 *   note (sans restriction de magasin pour une note de toute l'organisation) ; épingler : `notebook.manage` seulement.
 */
class NotebookAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins actifs dont l'utilisateur voit le carnet : droit de lecture et fonctionnalité activée.
     *
     * @return array<int, string> identifiant => nom
     */
    public function viewStores(User $user): array
    {
        $allowed = $this->authorizer->storeIdsFor($user, 'notebook.view');

        $query = Store::query()
            ->where('is_active', true)
            ->whereNotIn('id', StoreFeature::query()->where('feature', NotebookServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
            ->orderBy('name');

        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        $stores = [];
        foreach ($query->get(['id', 'name']) as $store) {
            $stores[$store->id] = $store->name;
        }

        return $stores;
    }

    /**
     * Magasins où l'utilisateur peut publier une note.
     *
     * @return array<int, string>
     */
    public function createStores(User $user): array
    {
        return array_filter(
            $this->viewStores($user),
            fn (int $storeId): bool => $this->authorizer->allows($user, 'notebook.create', $storeId),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function canCreateOrganizationWide(User $user): bool
    {
        return $this->authorizer->hasUnrestricted($user, 'notebook.create');
    }

    public function canCreateIn(User $user, ?int $storeId): bool
    {
        return $storeId === null
            ? $this->canCreateOrganizationWide($user)
            : isset($this->createStores($user)[$storeId]);
    }

    public function canManage(User $user, NotebookEntry $entry): bool
    {
        return $entry->store_id === null
            ? $this->authorizer->hasUnrestricted($user, 'notebook.manage')
            : $this->authorizer->allows($user, 'notebook.manage', $entry->store_id);
    }

    public function canEdit(User $user, NotebookEntry $entry): bool
    {
        return ($entry->author_id === $user->id && $this->canCreateIn($user, $entry->store_id)) || $this->canManage($user, $entry);
    }

    public function canView(User $user, NotebookEntry $entry): bool
    {
        return $entry->store_id === null
            ? $this->authorizer->allows($user, 'notebook.view')
            : isset($this->viewStores($user)[$entry->store_id]);
    }

    /**
     * Notes visibles, épinglées d'abord puis les plus récentes ; les expirées seulement sur demande.
     *
     * @return Builder<NotebookEntry>
     */
    public function visible(User $user, bool $withExpired = false): Builder
    {
        $storeIds = array_keys($this->viewStores($user));

        $query = NotebookEntry::query()
            ->with(['author:id,name', 'store:id,name'])
            ->where(function (Builder $q) use ($storeIds): void {
                $q->whereNull('store_id')->orWhereIn('store_id', $storeIds);
            })
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! $withExpired) {
            $query->where(function (Builder $q): void {
                $q->whereNull('expires_on')->orWhere('expires_on', '>=', NotebookEntry::today());
            });
        }

        return $query;
    }

    /**
     * Destinataires de la notification de publication : les comptes actifs qui peuvent voir la note, hors l'auteur
     * (membres du magasin pour une note de magasin, tous les lecteurs pour une note de toute l'organisation).
     *
     * @return list<int>
     */
    public function recipients(NotebookEntry $entry): array
    {
        $users = User::query()->where('is_active', true)->where('id', '!=', $entry->author_id);

        if ($entry->store_id !== null) {
            $users->whereIn('id', StoreMembership::query()->where('store_id', $entry->store_id)->where('is_active', true)->select('user_id'));
        }

        $ids = [];
        foreach ($users->get() as $candidate) {
            if ($this->canView($candidate, $entry)) {
                $ids[] = $candidate->id;
            }
        }

        return $ids;
    }
}
