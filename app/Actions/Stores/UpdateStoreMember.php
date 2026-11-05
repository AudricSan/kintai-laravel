<?php

namespace App\Actions\Stores;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\StoreMemberUpdated;

/**
 * Modifie la fiche d'un membre dans un magasin : code, embauche, contrat, état et rôle.
 */
class UpdateStoreMember
{
    use ManagesStoreMembers;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly StoreRoleManager $storeRoles,
    ) {}

    /**
     * `role_id` vide = rôle inchangé.
     *
     * @param  array<string, mixed>  $input  staff_code, hire_date, contract_type, is_active, role_id
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Store $store, User $target, array $input): void
    {
        $this->authorizeMembers($actor, $store);

        /** @var StoreMembership $membership */
        $membership = $store->members()->where('users.id', $target->id)->firstOrFail()->pivot;

        if (! $this->authorizer->canActOnUser($actor, 'employees.update', $target)) {
            throw new AuthorizationException;
        }

        $data = Validator::make($this->normalizeMemberInput($input), $this->memberRules($store, $membership->id) + [
            'is_active' => ['required', 'boolean'],
            'role_id' => ['nullable', 'integer'],
        ])->validate();

        $isActive = (bool) $data['is_active'];
        if ($membership->is_active && ! $isActive) {
            $this->storeRoles->assertNotSelf($actor, $target, 'is_active');
        }

        $role = null;
        if (isset($data['role_id'])) {
            $role = $this->storeRoles->resolveChange($actor, $target, $store, $data['role_id'], 'role_id');

            if ($role !== null) {
                $this->storeRoles->assertNotSelf($actor, $target, 'role_id');
            }
        }

        DB::transaction(function () use ($store, $target, $data, $isActive, $role): void {
            $store->members()->updateExistingPivot($target->id, [
                'staff_code' => $data['staff_code'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'contract_type' => $data['contract_type'] ?? null,
                'is_active' => $isActive,
            ]);

            if ($role !== null) {
                $this->storeRoles->apply($target, $store, $role);
            }
        });

        StoreMemberUpdated::dispatch($store->id, $target->id);
    }
}
