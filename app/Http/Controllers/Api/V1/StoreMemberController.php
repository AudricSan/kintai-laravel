<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Stores\AddStoreMember;
use App\Actions\Stores\RemoveStoreMember;
use App\Actions\Stores\UpdateStoreMember;
use App\Http\Requests\Api\V1\StoreMemberIndexRequest;
use App\Http\Resources\V1\StoreMemberResource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Membres d'un magasin. Lecture : `employees.view` sur le magasin ; écriture : `employees.update` (actions AddStoreMember,
 * UpdateStoreMember, RemoveStoreMember). Un magasin hors du périmètre de l'acteur est introuvable (404).
 */
class StoreMemberController extends ApiController
{
    public function index(Request $request, int $store): AnonymousResourceCollection
    {
        $model = $this->visible($request, $store, 'employees.view');
        $filters = $this->validated(StoreMemberIndexRequest::class);
        $term = trim((string) ($filters['search'] ?? ''));

        $members = $model->members()
            ->with('roles')
            ->when(isset($filters['is_active']), fn ($query) => $query->where('store_user.is_active', (bool) $filters['is_active']))
            ->when($term !== '', fn ($query) => $query->where('users.name', 'like', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%'))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate($this->perPage($request));

        return StoreMemberResource::collection($members);
    }

    public function show(Request $request, int $store, int $user): StoreMemberResource
    {
        $model = $this->visible($request, $store, 'employees.view');

        return $this->member($model, $user);
    }

    public function store(Request $request, int $store, AddStoreMember $add): JsonResponse
    {
        $model = $this->visible($request, $store, 'employees.update');

        $added = $add->handle($this->actor($request), $model, $request->only(['user_id', 'role_id', 'staff_code', 'hire_date', 'contract_type']));

        return $this->member($model, $added->id)->response()->setStatusCode(201);
    }

    public function update(Request $request, int $store, int $user, UpdateStoreMember $update): StoreMemberResource
    {
        $model = $this->visible($request, $store, 'employees.update');
        $member = $model->members()->where('users.id', $user)->firstOrFail();
        $pivot = $member->pivot;

        // PUT partiel toléré : une clé absente garde la valeur actuelle.
        $current = [
            'staff_code' => $pivot->staff_code,
            'hire_date' => $pivot->hire_date?->toDateString(),
            'contract_type' => $pivot->contract_type,
            'is_active' => $pivot->is_active,
        ];
        $input = $request->only(['staff_code', 'hire_date', 'contract_type', 'is_active', 'role_id']) + $current;

        $update->handle($this->actor($request), $model, $member, $input);

        return $this->member($model, $user);
    }

    public function destroy(Request $request, int $store, int $user, RemoveStoreMember $remove): Response
    {
        $model = $this->visible($request, $store, 'employees.update');
        $member = $model->members()->where('users.id', $user)->firstOrFail();

        $remove->handle($this->actor($request), $model, $member);

        return response()->noContent();
    }

    private function member(Store $store, int $userId): StoreMemberResource
    {
        /** @var User $member */
        $member = $store->members()->with('roles')->where('users.id', $userId)->firstOrFail();

        return new StoreMemberResource($member);
    }

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    private function visible(Request $request, int $storeId, string $permission): Store
    {
        $actor = $this->actor($request);

        if (! $this->authorizer()->allows($actor, $permission)) {
            throw new AuthorizationException;
        }

        $store = Store::query()->findOrFail($storeId);

        if (! $this->authorizer()->allows($actor, 'stores.view', $store)) {
            throw (new ModelNotFoundException)->setModel(Store::class, [$storeId]);
        }

        if (! $this->authorizer()->allows($actor, $permission, $store)) {
            throw new AuthorizationException;
        }

        return $store;
    }
}
