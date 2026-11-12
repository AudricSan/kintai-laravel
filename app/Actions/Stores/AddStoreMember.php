<?php

namespace App\Actions\Stores;

use App\Authorization\Authorizer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\StoreMemberAdded;

/**
 * Ajoute un utilisateur actif à un magasin avec son rôle (par défaut `employee`).
 */
class AddStoreMember
{
    use ManagesStoreMembers;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly StoreRoleManager $storeRoles,
    ) {}

    /**
     * @param  array<string, mixed>  $input  user_id, role_id (facultatif), staff_code, hire_date, contract_type
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Store $store, array $input): User
    {
        $this->authorizeMembers($actor, $store);

        $data = Validator::make($this->normalizeMemberInput($input), $this->memberRules($store) + [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'role_id' => ['nullable', 'integer'],
        ])->validate();

        $target = User::query()->findOrFail($data['user_id']);

        if ($this->authorizer->isOwner($target) && ! $this->authorizer->isOwner($actor)) {
            throw new AuthorizationException;
        }

        if (! $target->is_active) {
            throw ValidationException::withMessages(['user_id' => __('This account is inactive.')]);
        }

        if ($store->members()->where('users.id', $target->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => __('This employee already belongs to this store.')]);
        }

        $roleId = $data['role_id'] ?? Role::query()->where('slug', 'employee')->value('id');
        $role = $this->storeRoles->resolveChange($actor, $target, $store, $roleId, 'role_id');

        // Une attribution orpheline du magasin (retrait ancien) n'est pas « inchangée » : on pose toujours le rôle.
        $role ??= Role::query()->findOrFail($roleId);
        $this->storeRoles->assertNotSelf($actor, $target, 'user_id');

        DB::transaction(function () use ($store, $target, $data, $role): void {
            $store->members()->attach($target->id, [
                'staff_code' => $data['staff_code'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'contract_type' => $data['contract_type'] ?? null,
                'is_active' => true,
            ]);

            $this->storeRoles->apply($target, $store, $role);
        });

        StoreMemberAdded::dispatch($store->id, $target->id);

        return $target;
    }
}
