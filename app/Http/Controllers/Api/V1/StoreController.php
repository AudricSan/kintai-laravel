<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Stores\CreateStore;
use App\Actions\Stores\DeleteStore;
use App\Actions\Stores\UpdateStore;
use App\Http\Resources\V1\StoreDetailResource;
use App\Models\Store;
use App\Stores\StoreFeatureRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Magasins. Un magasin hors du périmètre de l'acteur (`stores.view`) est introuvable (404) ; sans `stores.view` nulle part, 403.
 */
class StoreController extends ApiController
{
    /** Réglages repris tels quels lors d'un PUT partiel. */
    private const SETTINGS = [
        'code', 'name', 'timezone', 'currency', 'currency_symbol_style', 'locale', 'email', 'phone', 'address_street', 'address_city',
        'address_postal', 'address_country', 'is_active', 'week_start_day', 'low_staff_hour_start', 'low_staff_hour_end',
        'min_staff_per_day', 'min_shift_minutes', 'max_shift_minutes',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, 'stores.view')) {
            throw new AuthorizationException;
        }

        $filters = $request->validate(['is_active' => ['nullable', 'boolean']]);
        $ids = $this->authorizer()->storeIdsFor($actor, 'stores.view');

        $stores = Store::query()
            ->with('features')
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return StoreDetailResource::collection($stores);
    }

    public function show(Request $request, int $store): StoreDetailResource
    {
        return new StoreDetailResource($this->visible($request, $store)->load('features'));
    }

    public function store(Request $request, CreateStore $create): JsonResponse
    {
        // Valeurs par défaut du formulaire web ; toutes les fonctionnalités sont activées.
        $defaults = ['is_active' => true, 'week_start_day' => 1, 'min_staff_per_day' => 0, 'min_shift_minutes' => 120, 'max_shift_minutes' => 480];
        $input = $request->only(self::SETTINGS) + $defaults;
        $input['features'] = (array) $request->input('features', []) + array_fill_keys(app(StoreFeatureRegistry::class)->keys(), true);

        $created = $create->handle($this->actor($request), $input);

        return (new StoreDetailResource($created->refresh()->load('features')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $store, UpdateStore $update): StoreDetailResource
    {
        $model = $this->visible($request, $store);

        // PUT partiel toléré : une clé absente garde la valeur actuelle (l'action exige l'enregistrement complet).
        $current = $model->only(self::SETTINGS);
        $input = $request->only(self::SETTINGS) + $current;
        $input['features'] = array_merge($this->currentFeatures($model), (array) $request->input('features', []));

        $update->handle($this->actor($request), $model, $input);

        return new StoreDetailResource($model->refresh()->load('features'));
    }

    public function destroy(Request $request, int $store, DeleteStore $delete): Response
    {
        $delete->handle($this->actor($request), $this->visible($request, $store));

        return response()->noContent();
    }

    /**
     * @return array<string, bool>
     */
    private function currentFeatures(Store $store): array
    {
        $features = [];
        foreach (app(StoreFeatureRegistry::class)->keys() as $key) {
            $features[$key] = $store->hasFeature($key);
        }

        return $features;
    }

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    private function visible(Request $request, int $storeId): Store
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, 'stores.view')) {
            throw new AuthorizationException;
        }

        $store = Store::query()->findOrFail($storeId);

        if (! $this->authorizer()->allows($actor, 'stores.view', $store)) {
            throw (new ModelNotFoundException)->setModel(Store::class, [$storeId]);
        }

        return $store;
    }
}
