<?php

namespace App\Http\Controllers\Api\V1;

use App\Activity\ActivityQuery;
use App\Http\Resources\V1\ActivityEntryResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Journal d'activité, avec les filtres et la restriction par magasin de l'écran web (ActivityQuery) : `stores.view`
 * est exigé, et un gérant restreint ne voit que les entrées de ses magasins.
 */
class ActivityController extends ApiController
{
    public function index(Request $request, ActivityQuery $activity): AnonymousResourceCollection
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, 'stores.view')) {
            throw new AuthorizationException;
        }

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer'],
            'store' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ActivityEntryResource::collection(
            $activity->for($actor, $filters)->with(['actor', 'store'])->paginate($this->perPage($request))
        );
    }
}
