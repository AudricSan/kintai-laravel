<?php

namespace App\Actions\Employees;

use App\Authorization\Authorizer;
use App\Authorization\OwnerGuard;
use App\Concerns\EmployeeValidationRules;
use App\Events\EmployeeUpdated;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre l'identité, les données RH, l'état du compte, la case Owner et le rôle par magasin d'un employé.
 */
class UpdateEmployee
{
    use AuthorizesEmployeeActions;
    use EmployeeValidationRules;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OwnerGuard $ownerGuard,
    ) {}

    /**
     * Magasins de l'employé sur lesquels l'acteur peut agir (rôles par magasin).
     *
     * @return list<int>
     */
    public function manageableStoreIds(User $actor, User $target): array
    {
        $targetStoreIds = DB::table('store_user')
            ->where('user_id', $target->id)
            ->pluck('store_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $allowed = $this->authorizer->storeIdsFor($actor, 'employees.update');

        return array_values($allowed === null ? $targetStoreIds : array_intersect($targetStoreIds, $allowed));
    }

    /**
     * `store_roles` : identifiant de magasin => identifiant de rôle (vide = inchangé).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, array $input): void
    {
        $this->authorizeOnEmployee($actor, 'employees.update', $target);

        $input = $this->normalizeEmployeeInput($input);

        $data = Validator::make($input, $this->identityRules($target->id) + $this->hrRules() + [
            'is_active' => ['required', 'boolean'],
            'is_owner' => ['required', 'boolean'],
            'store_roles' => ['nullable', 'array'],
            'store_roles.*' => ['nullable', 'integer'],
        ])->validate();

        $isActive = (bool) $data['is_active'];
        $wantsOwner = (bool) $data['is_owner'];

        $this->guardOwnerChanges($actor, $target, $wantsOwner, $isActive);
        $storeRoles = $this->resolveStoreRoles($actor, $target, $data['store_roles'] ?? []);

        DB::transaction(function () use ($target, $data, $isActive, $wantsOwner, $storeRoles): void {
            $target->fill([
                'name' => $this->displayName($data),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'furigana_first_name' => $data['furigana_first_name'],
                'furigana_last_name' => $data['furigana_last_name'],
                'email' => $data['email'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'color' => $data['color'] ?? '#3B82F6',
                'is_active' => $isActive,
            ])->save();

            $hr = [];
            foreach (array_keys($this->hrRules()) as $field) {
                $hr[$field] = $data[$field] ?? null;
            }
            $target->profile()->updateOrCreate([], $hr);

            $ownerRole = Role::query()->where('is_system', true)->firstOrFail();
            if ($wantsOwner) {
                $target->assignRole($ownerRole);
            } else {
                $target->removeRole($ownerRole);
            }

            foreach ($storeRoles as [$store, $role]) {
                RoleAssignment::query()->where('user_id', $target->id)->where('store_id', $store->id)->delete();
                $target->assignRole($role, $store);
            }

            $this->authorizer->forget($target);
        });

        EmployeeUpdated::dispatch($target->id);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function guardOwnerChanges(User $actor, User $target, bool $wantsOwner, bool $isActive): void
    {
        $isOwner = $this->authorizer->isOwner($target);

        if ($wantsOwner !== $isOwner && ! $this->authorizer->isOwner($actor)) {
            throw new AuthorizationException;
        }

        if ($isOwner && ! $wantsOwner) {
            $this->guard('is_owner', fn () => $this->ownerGuard->assertCanRemoveOwner($actor, $target));
        }

        if ($target->is_active && ! $isActive) {
            $this->guard('is_active', function () use ($actor, $target): void {
                $this->ownerGuard->assertNotSelfUnlessOwner($actor, $target);
                $this->ownerGuard->assertCanRemoveOwner($actor, $target);
            });
        }
    }

    /**
     * Rôles à poser, par magasin, pour les seuls magasins dont le rôle change.
     *
     * @param  array<array-key, mixed>  $requested
     * @return list<array{0: Store, 1: Role}>
     *
     * @throws ValidationException
     */
    private function resolveStoreRoles(User $actor, User $target, array $requested): array
    {
        $manageable = $this->manageableStoreIds($actor, $target);
        $changes = [];

        foreach ($requested as $storeId => $roleId) {
            if ($roleId === null || $roleId === '') {
                continue;
            }

            $field = 'store_roles.'.$storeId;

            if (! in_array((int) $storeId, $manageable, true)) {
                throw ValidationException::withMessages([$field => __('You cannot assign this role.')]);
            }

            $role = Role::query()->find($roleId);
            $store = Store::query()->find($storeId);

            if ($role === null || $store === null || $role->is_system) {
                throw ValidationException::withMessages([$field => __('You cannot assign this role.')]);
            }

            $current = RoleAssignment::query()
                ->where('user_id', $target->id)
                ->where('store_id', $store->id)
                ->pluck('role_id')
                ->all();

            if ($current === [$role->id]) {
                continue;
            }

            if (! $this->authorizer->canAssignRole($actor, $role, $store)) {
                throw ValidationException::withMessages([$field => __('You cannot assign this role.')]);
            }

            $changes[] = [$store, $role];
        }

        // Un non-Owner ne change pas ses propres rôles : il perdrait des droits sans pouvoir revenir en arrière.
        if ($changes !== []) {
            $this->guard('store_roles.'.$changes[0][0]->id, fn () => $this->ownerGuard->assertNotSelfUnlessOwner($actor, $target));
        }

        return $changes;
    }

    /**
     * @param  callable(): void  $check
     *
     * @throws ValidationException
     */
    private function guard(string $field, callable $check): void
    {
        try {
            $check();
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
