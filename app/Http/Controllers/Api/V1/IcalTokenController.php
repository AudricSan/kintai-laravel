<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ical\DeleteIcalToken;
use App\Actions\Ical\GenerateIcalToken;
use App\Http\Resources\V1\IcalTokenResource;
use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Liens iCal d'un employé, un par magasin. Le jeton en clair (et l'URL du flux) n'est renvoyé qu'à la création et à la
 * régénération ; ensuite seule l'existence du lien peut être lue.
 */
class IcalTokenController extends ApiController
{
    public function index(Request $request, int $user): AnonymousResourceCollection
    {
        $target = $this->targetUser($request, $user, 'employees.view');

        return IcalTokenResource::collection(IcalToken::query()->with('store')->where('user_id', $target->id)->orderBy('store_id')->get());
    }

    public function show(Request $request, int $user, int $store): IcalTokenResource
    {
        $target = $this->targetUser($request, $user, 'employees.view');

        return new IcalTokenResource($this->existing($target, $store)->load('store'));
    }

    public function store(Request $request, int $user, GenerateIcalToken $generate): JsonResponse
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $data = $request->validate(['store_id' => ['required', 'integer']]);
        $store = Store::query()->findOrFail($data['store_id']);

        if (IcalToken::query()->where('user_id', $target->id)->where('store_id', $store->id)->exists()) {
            throw ValidationException::withMessages(['store_id' => __('A calendar link already exists for this store: regenerate it instead.')]);
        }

        return $this->issued($request, $generate, $target, $store)->response()->setStatusCode(201);
    }

    public function regenerate(Request $request, int $user, int $store, GenerateIcalToken $generate): IcalTokenResource
    {
        $target = $this->targetUser($request, $user, 'employees.update');

        return $this->issued($request, $generate, $target, Store::query()->findOrFail($store));
    }

    public function destroy(Request $request, int $user, int $store, DeleteIcalToken $delete): Response
    {
        $target = $this->targetUser($request, $user, 'employees.update');
        $this->existing($target, $store);

        $delete->handle($this->actor($request), $target, Store::query()->findOrFail($store));

        return response()->noContent();
    }

    private function issued(Request $request, GenerateIcalToken $generate, User $target, Store $store): IcalTokenResource
    {
        $plain = $generate->handle($this->actor($request), $target, $store);
        $record = IcalToken::query()->with('store')->where('user_id', $target->id)->where('store_id', $store->id)->firstOrFail();

        return new IcalTokenResource($record, $plain);
    }

    private function existing(User $target, int $storeId): IcalToken
    {
        return IcalToken::query()->where('user_id', $target->id)->where('store_id', $storeId)->firstOrFail();
    }
}
