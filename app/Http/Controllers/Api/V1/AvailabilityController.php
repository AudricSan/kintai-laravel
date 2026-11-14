<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Availability\SaveAvailability;
use App\Http\Requests\Api\V1\AvailabilityIndexRequest;
use App\Http\Requests\Api\V1\AvailabilityUpdateRequest;
use App\Http\Resources\V1\AvailabilityResource;
use App\Models\Availability;
use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Disponibilités hebdomadaires. Lecture : les siennes, ou, avec `shifts.view`, celles des magasins visibles. Écriture :
 * uniquement les siennes, par magasin et pour la semaine entière (action SaveAvailability).
 */
class AvailabilityController extends ApiController
{
    public function index(AvailabilityIndexRequest $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $filters = $request->validated();

        $query = Availability::query();

        if ($this->authorizer()->allows($actor, 'shifts.view')) {
            $ids = $this->authorizer()->storeIdsFor($actor, 'shifts.view');

            if ($ids !== null) {
                $query->where(fn (Builder $q) => $q->whereIn('store_id', $ids)->orWhere('user_id', $actor->id));
            }
        } else {
            if (isset($filters['user_id']) && (int) $filters['user_id'] !== $actor->id) {
                throw new AuthorizationException;
            }

            $query->where('user_id', $actor->id);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (isset($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        return AvailabilityResource::collection(
            $query->orderBy('user_id')->orderBy('store_id')->orderBy('day_of_week')->paginate($this->perPage($request))
        );
    }

    /**
     * Remplace la semaine entière. `days` : objet indexé par le jour ISO (`"1"` = lundi ... `"7"` = dimanche), chaque jour
     * `{available, start, end}` ; un jour absent devient indisponible.
     */
    public function update(Request $request, int $user, int $store, SaveAvailability $save): AnonymousResourceCollection
    {
        $actor = $this->actor($request);

        // Seul l'employé modifie ses disponibilités : toute autre cible est refusée avant de chercher quoi que ce soit.
        if ($actor->id !== $user) {
            throw new AuthorizationException;
        }

        $data = $this->validated(AvailabilityUpdateRequest::class);
        $model = Store::query()->findOrFail($store);

        foreach (array_keys($data['days']) as $day) {
            if (! in_array((int) $day, range(1, 7), true) || ! is_array($data['days'][$day])) {
                throw ValidationException::withMessages(['days' => trans('validation.in', ['attribute' => 'days'])]);
            }
        }

        $save->handle($actor, $actor, $model, $data['days']);

        return AvailabilityResource::collection(
            Availability::query()->where('user_id', $actor->id)->where('store_id', $model->id)->orderBy('day_of_week')->get()
        );
    }
}
