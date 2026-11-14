<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ShiftTypes\DeleteShiftType;
use App\Actions\ShiftTypes\SaveShiftType;
use App\Actions\ShiftTypes\ToggleShiftTypeStore;
use App\Http\Requests\Api\V1\ShiftTypeIndexRequest;
use App\Http\Resources\V1\ShiftTypeResource;
use App\Models\ShiftType;
use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Types de shift. Lecture : `shifts.view` (types d'un magasin visible) ; écriture : `shifts.update`, magasin par magasin
 * (actions SaveShiftType, DeleteShiftType, ToggleShiftTypeStore). Un type hors du périmètre de l'acteur est introuvable (404).
 */
class ShiftTypeController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $this->requireView($request);

        $filters = $this->validated(ShiftTypeIndexRequest::class);
        $viewable = $this->authorizer()->storeIdsFor($actor, 'shifts.view');

        $types = ShiftType::query()
            ->with('stores:id')
            ->when($viewable !== null, fn (Builder $query) => $query->whereHas('stores', fn (Builder $q) => $q->whereIn('stores.id', $viewable)))
            ->when(isset($filters['store_id']), fn (Builder $query) => $query->whereHas('stores', fn (Builder $q) => $q->where('stores.id', (int) $filters['store_id'])))
            ->when(isset($filters['is_active']), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ShiftTypeResource::collection($types);
    }

    public function show(Request $request, int $type): ShiftTypeResource
    {
        return new ShiftTypeResource($this->visible($request, $type)->load('stores:id'));
    }

    public function store(Request $request, SaveShiftType $save): JsonResponse
    {
        $type = $save->handle($this->actor($request), null, $request->only($this->fields()));

        return (new ShiftTypeResource($type->refresh()->load('stores:id')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $type, SaveShiftType $save): ShiftTypeResource
    {
        $model = $this->visible($request, $type)->load('stores:id');

        // PUT partiel toléré : une clé absente garde la valeur actuelle (l'action exige l'enregistrement complet).
        $current = [
            'code' => $model->code,
            'name' => $model->name,
            'starts_at' => $model->starts_at,
            'ends_at' => $model->ends_at,
            'hourly_rate' => $model->hourly_rate,
            'color' => $model->color,
            'sort_order' => $model->sort_order,
            'is_active' => $model->is_active,
            'store_ids' => $model->stores->pluck('id')->all(),
        ];

        $saved = $save->handle($this->actor($request), $model, $request->only($this->fields()) + $current);

        return new ShiftTypeResource($saved->refresh()->load('stores:id'));
    }

    public function destroy(Request $request, int $type, DeleteShiftType $delete): Response
    {
        $delete->handle($this->actor($request), $this->visible($request, $type));

        return response()->noContent();
    }

    /**
     * Rattache le type au magasin (sans effet s'il l'est déjà).
     */
    public function attach(Request $request, int $type, int $store, ToggleShiftTypeStore $toggle): ShiftTypeResource
    {
        return $this->setAttached($request, $type, $store, $toggle, true);
    }

    /**
     * Détache le type du magasin (sans effet s'il ne l'est pas) ; un type reste dans au moins un magasin (422).
     */
    public function detach(Request $request, int $type, int $store, ToggleShiftTypeStore $toggle): ShiftTypeResource
    {
        return $this->setAttached($request, $type, $store, $toggle, false);
    }

    private function setAttached(Request $request, int $typeId, int $storeId, ToggleShiftTypeStore $toggle, bool $attached): ShiftTypeResource
    {
        $actor = $this->actor($request);
        $type = $this->visible($request, $typeId);
        $store = Store::query()->findOrFail($storeId);

        if (! $this->authorizer()->allows($actor, 'shifts.update', $store)) {
            throw new AuthorizationException;
        }

        if ($type->stores()->where('stores.id', $store->id)->exists() !== $attached) {
            $toggle->handle($actor, $type, $store);
        }

        return new ShiftTypeResource($type->refresh()->load('stores:id'));
    }

    /**
     * @return list<string>
     */
    private function fields(): array
    {
        return ['code', 'name', 'starts_at', 'ends_at', 'hourly_rate', 'color', 'sort_order', 'is_active', 'store_ids'];
    }

    /**
     * @throws AuthorizationException
     */
    private function requireView(Request $request): void
    {
        if (! $this->authorizer()->allows($this->actor($request), 'shifts.view')) {
            throw new AuthorizationException;
        }
    }

    private function visible(Request $request, int $typeId): ShiftType
    {
        $this->requireView($request);

        $viewable = $this->authorizer()->storeIdsFor($this->actor($request), 'shifts.view');

        return ShiftType::query()
            ->when($viewable !== null, fn (Builder $query) => $query->whereHas('stores', fn (Builder $q) => $q->whereIn('stores.id', $viewable)))
            ->findOrFail($typeId);
    }
}
