<?php

namespace App\Actions\Employees;

use App\Authorization\Authorizer;
use App\Authorization\PasswordDefaults;
use App\Concerns\EmployeeValidationRules;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\EmployeeCreated;

/**
 * Crée un employé, son appartenance à un magasin et son rôle. Réutilisable (formulaire, import Excel…).
 */
class CreateEmployee
{
    use EmployeeValidationRules;

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $input, string $source = 'form'): User
    {
        if (! $this->authorizer->allows($actor, 'employees.create')) {
            throw new AuthorizationException;
        }

        $input = $this->normalizeEmployeeInput($input);
        $storeIds = $this->authorizer->storeIdsFor($actor, 'employees.create');

        $data = Validator::make($input, $this->identityRules() + [
            'password' => ['nullable', 'string', Password::defaults()],
            'store_id' => $storeIds === null
                ? ['nullable', 'integer', Rule::exists('stores', 'id')->whereNull('deleted_at')]
                : ['required', 'integer', Rule::in($storeIds)],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'is_owner' => ['nullable', 'boolean'],
        ])->validate();

        $isOwner = (bool) ($data['is_owner'] ?? false);

        if ($isOwner && ! $this->authorizer->isOwner($actor)) {
            throw new AuthorizationException;
        }

        $store = isset($data['store_id']) ? Store::query()->findOrFail($data['store_id']) : null;
        $role = $this->roleFor($actor, $data, $store, $isOwner);
        $usesDefaultPassword = ! isset($data['password']);

        $user = DB::transaction(function () use ($data, $store, $role, $isOwner, $usesDefaultPassword): User {
            $user = new User;
            $user->fill([
                'name' => $this->displayName($data),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'furigana_first_name' => $data['furigana_first_name'],
                'furigana_last_name' => $data['furigana_last_name'],
                'email' => $data['email'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'color' => $data['color'] ?? '#3B82F6',
                'password' => $data['password'] ?? PasswordDefaults::DEFAULT_PASSWORD,
            ]);
            $user->must_change_password = $usesDefaultPassword;
            $user->save();

            $store?->members()->attach($user->id);

            if ($isOwner) {
                $user->assignRole(Role::query()->where('is_system', true)->firstOrFail());
            } elseif ($store !== null && $role !== null) {
                $user->assignRole($role, $store);
            }

            return $user;
        });

        EmployeeCreated::dispatch($user->id, $source);

        return $user;
    }

    /**
     * Rôle du magasin : celui demandé, sinon `employee`. Un Owner n'a pas de rôle par magasin.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function roleFor(User $actor, array $data, ?Store $store, bool $isOwner): ?Role
    {
        if ($store === null || $isOwner) {
            return null;
        }

        $role = isset($data['role_id'])
            ? Role::query()->findOrFail($data['role_id'])
            : Role::query()->where('slug', 'employee')->first();

        if ($role === null) {
            return null;
        }

        if ($role->is_system || ! $this->authorizer->canAssignRole($actor, $role, $store)) {
            throw ValidationException::withMessages(['role_id' => __('You cannot assign this role.')]);
        }

        return $role;
    }
}
