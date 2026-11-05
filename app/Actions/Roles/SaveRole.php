<?php

namespace App\Actions\Roles;

use App\Authorization\Authorizer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Events\RoleSaved;

/**
 * Crée ou modifie un rôle et ses permissions (réservé à l'Owner). Le slug est fixé à la création.
 */
class SaveRole
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * `permissions` : clés cochées ; `all_stores` : parmi elles, celles qui ignorent la portée magasin.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, ?Role $role, array $input): Role
    {
        if (! Gate::forUser($actor)->allows('manage-roles') || $role?->is_system) {
            throw new AuthorizationException;
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_manager' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($this->registry->all())],
            'all_stores' => ['nullable', 'array'],
            'all_stores.*' => ['string', Rule::in($this->registry->all())],
        ])->validate();

        $permissions = array_values(array_unique($data['permissions'] ?? []));
        $allStores = array_values(array_intersect(array_unique($data['all_stores'] ?? []), $permissions));

        $created = $role === null;

        $role = DB::transaction(function () use ($role, $data, $permissions, $allStores): Role {
            $attributes = [
                'name' => trim($data['name']),
                'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
                'color' => $data['color'],
                'is_manager' => (bool) ($data['is_manager'] ?? false),
            ];

            if ($role === null) {
                $role = new Role;
                $role->fill($attributes);
                $role->slug = $this->uniqueSlug($attributes['name']);
                $role->save();
            } else {
                $role->fill($attributes)->save();
            }

            // Les permissions hors registre (bundle désinstallé) sont conservées telles quelles.
            $role->permissions()->whereIn('permission', $this->registry->all())->whereNotIn('permission', $permissions)->delete();

            foreach ($permissions as $permission) {
                $role->permissions()->updateOrCreate(
                    ['permission' => $permission],
                    ['all_stores' => in_array($permission, $allStores, true)],
                );
            }

            return $role;
        });

        $this->authorizer->forget();

        RoleSaved::dispatch($role->id, $created);

        return $role;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;

        for ($suffix = 2; Role::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
