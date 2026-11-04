<?php

namespace App\Http\Resources\V1;

use App\Authorization\Authorizer;
use App\Authorization\EffectivePermissions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /auth/me` : le compte, ses rôles, ses magasins et ses permissions effectives (globales, puis par magasin).
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $authorizer = app(Authorizer::class);
        $permissions = app(EffectivePermissions::class);
        /** @var User $user */
        $user = $this->resource;
        $memberships = $user->stores()->get()->keyBy('id');

        return [
            'user' => new UserResource($user),
            'is_owner' => $authorizer->isOwner($user),
            'is_manager' => $authorizer->isManager($user),
            'roles' => RoleResource::collection($user->roles()->orderBy('roles.name')->get()),
            'permissions' => ['global' => $permissions->global($user)],
            'stores' => $permissions->byStore($user)->map(
                fn (array $entry): array => (new StoreResource($memberships->get($entry['store']->id) ?? $entry['store']))->resolve($request)
                    + ['permissions' => $entry['permissions']],
            )->all(),
        ];
    }
}
