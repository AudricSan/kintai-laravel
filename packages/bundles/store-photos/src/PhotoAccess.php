<?php

namespace Kintai\Bundles\StorePhotos;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\StorePhotos\Models\StorePhotoImage;
use Kintai\Bundles\StorePhotos\Models\StorePhotoSubmission;

/**
 * Qui voit, envoie, tourne et supprime les photos, avec la portée magasin du Core (`Authorizer`) :
 *
 * - `photos.view` : voir les envois du magasin ;
 * - `photos.create` : envoyer des photos (magasin dont la fonctionnalité `photos` est activée) ;
 * - `photos.update` : tourner une photo ;
 * - `photos.delete` : supprimer un envoi ;
 * - les réglages de rétention sont réservés à l'Owner.
 *
 * Une photo se lit (route contrôlée) avec `photos.view` sur son magasin, ou en étant membre actif de ce magasin.
 */
class PhotoAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function isOwner(User $user): bool
    {
        return $this->authorizer->isOwner($user);
    }

    /**
     * Magasins dont l'utilisateur voit les envois.
     *
     * @return array<int, string> identifiant => nom
     */
    public function viewStores(User $user): array
    {
        return $this->stores($user, 'photos.view', false);
    }

    /**
     * Magasins où l'utilisateur peut envoyer des photos (droit et fonctionnalité activée).
     *
     * @return array<int, string> identifiant => nom
     */
    public function createStores(User $user): array
    {
        return $this->stores($user, 'photos.create', true);
    }

    /**
     * @return array<int, string>
     */
    private function stores(User $user, string $permission, bool $requireFeature): array
    {
        $ids = $this->authorizer->storeIdsFor($user, $permission);

        if ($ids === []) {
            return [];
        }

        $stores = [];
        $query = Store::query()->orderBy('name')
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids ?? []))
            ->when($requireFeature, fn (Builder $q) => $q->whereNotIn('id', StoreFeature::query()->where('feature', StorePhotosServiceProvider::FEATURE)->where('enabled', false)->select('store_id')));

        foreach ($query->get(['id', 'name']) as $store) {
            $stores[$store->id] = $store->name;
        }

        return $stores;
    }

    public function featureEnabled(int $storeId): bool
    {
        return ! StoreFeature::query()->where('store_id', $storeId)->where('feature', StorePhotosServiceProvider::FEATURE)->where('enabled', false)->exists();
    }

    public function canView(User $user, StorePhotoSubmission $submission): bool
    {
        return $this->authorizer->allows($user, 'photos.view', $submission->store_id);
    }

    public function canCreateIn(User $user, int $storeId): bool
    {
        return $this->authorizer->allows($user, 'photos.create', $storeId) && $this->featureEnabled($storeId);
    }

    public function canRotate(User $user, StorePhotoSubmission $submission): bool
    {
        return $this->authorizer->allows($user, 'photos.update', $submission->store_id) && $this->featureEnabled($submission->store_id);
    }

    public function canDelete(User $user, StorePhotoSubmission $submission): bool
    {
        return $this->authorizer->allows($user, 'photos.delete', $submission->store_id);
    }

    /**
     * Lecture du fichier d'une photo : droit de voir les envois du magasin, ou membre actif du magasin.
     */
    public function canReadImage(User $user, StorePhotoImage $image): bool
    {
        $storeId = $image->submission->store_id;

        return $this->authorizer->allows($user, 'photos.view', $storeId)
            || StoreMembership::query()->where('store_id', $storeId)->where('user_id', $user->id)->where('is_active', true)->exists();
    }

    /**
     * Envois visibles par l'utilisateur.
     *
     * @return Builder<StorePhotoSubmission>
     */
    public function visible(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'photos.view');

        return StorePhotoSubmission::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }
}
