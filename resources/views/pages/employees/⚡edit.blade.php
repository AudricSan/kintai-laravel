<?php

use App\Actions\Employees\DeleteEmployee;
use App\Actions\Employees\ResetEmployeePassword;
use App\Actions\Employees\UpdateEmployee;
use App\Actions\Employees\UpdateEmployeeRates;
use App\Authorization\Authorizer;
use App\Authorization\PasswordDefaults;
use App\Concerns\EmployeeValidationRules;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit employee')] class extends Component {
    use EmployeeValidationRules;

    #[Locked]
    public int $employeeId = 0;

    public string $last_name = '';

    public string $first_name = '';

    public string $furigana_last_name = '';

    public string $furigana_first_name = '';

    public string $name = '';

    public string $email = '';

    public string $employee_code = '';

    public string $color = '#3B82F6';

    public bool $is_active = true;

    public bool $is_owner = false;

    /** @var array<int|string, string> identifiant de magasin => identifiant de rôle */
    public array $storeRoles = [];

    /** @var array<string, string> */
    public array $hr = [];

    public string $password = '';

    public bool $must_change = true;

    /** @var array<int|string, string> identifiant de type de shift => taux personnel */
    public array $rates = [];

    public function mount(User $user): void
    {
        $this->employeeId = $user->id;
        $this->authorizeView($user);
        $this->fillFromEmployee($user);
    }

    private function employee(): User
    {
        return User::query()->findOrFail($this->employeeId);
    }

    private function authorizeView(User $user): void
    {
        $authorizer = app(Authorizer::class);

        abort_unless(
            $authorizer->allows(Auth::user(), 'employees.view') && $authorizer->canActOnUser(Auth::user(), 'employees.view', $user),
            403,
        );
    }

    private function canUpdate(User $user): bool
    {
        $authorizer = app(Authorizer::class);

        return $authorizer->allows(Auth::user(), 'employees.update') && $authorizer->canActOnUser(Auth::user(), 'employees.update', $user);
    }

    private function fillFromEmployee(User $user): void
    {
        $this->last_name = $user->last_name;
        $this->first_name = $user->first_name;
        $this->furigana_last_name = $user->furigana_last_name ?? '';
        $this->furigana_first_name = $user->furigana_first_name ?? '';
        $this->name = $user->name;
        $this->email = $user->email ?? '';
        $this->employee_code = $user->employee_code ?? '';
        $this->color = $user->color;
        $this->is_active = $user->is_active;
        $this->is_owner = app(Authorizer::class)->isOwner($user);

        $profile = $user->profile;
        $this->hr = [];
        foreach (array_keys($this->hrRules()) as $field) {
            $value = $profile?->{$field};
            $this->hr[$field] = $value === null ? '' : ($field === 'birth_date' ? $value->format('Y-m-d') : (string) $value);
        }

        $this->storeRoles = [];
        foreach (app(UpdateEmployee::class)->manageableStoreIds(Auth::user(), $user) as $storeId) {
            $roleId = RoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->orderBy('id')
                ->value('role_id');
            $this->storeRoles[$storeId] = $roleId === null ? '' : (string) $roleId;
        }

        $this->fillRates($user);
    }

    private function fillRates(User $user): void
    {
        $personal = $user->personalRates()->get()->mapWithKeys(
            fn ($type): array => [$type->id => number_format((float) $type->pivot->hourly_rate, 2, '.', '')],
        );

        $this->rates = [];
        foreach (app(UpdateEmployeeRates::class)->eligibleShiftTypes(Auth::user(), $user) as $type) {
            $this->rates[$type->id] = $personal[$type->id] ?? '';
        }
    }

    public function updatedEmployeeCode(): void
    {
        $this->employee_code = Str::upper(trim($this->employee_code));
        $this->validateOnly('employee_code', $this->identityRules($this->employeeId));
    }

    public function updatedEmail(): void
    {
        $this->email = Str::lower(trim($this->email));
        $this->validateOnly('email', $this->identityRules($this->employeeId));
    }

    public function save(UpdateEmployee $updateEmployee): void
    {
        $user = $this->employee();

        $updateEmployee->handle(Auth::user(), $user, [
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'furigana_last_name' => $this->furigana_last_name,
            'furigana_first_name' => $this->furigana_first_name,
            'name' => $this->name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'is_owner' => $this->is_owner,
            'store_roles' => $this->storeRoles,
        ] + $this->hr);

        $this->fillFromEmployee($user->refresh());
        Flux::toast(variant: 'success', text: __('Employee saved.'));
    }

    public function resetPassword(ResetEmployeePassword $resetPassword): void
    {
        $resetPassword->handle(Auth::user(), $this->employee());

        $this->reset('password');
        Flux::toast(variant: 'success', text: __('Password reset to :password.', ['password' => PasswordDefaults::DEFAULT_PASSWORD]));
    }

    public function setPassword(ResetEmployeePassword $resetPassword): void
    {
        $resetPassword->handle(Auth::user(), $this->employee(), $this->password, $this->must_change);

        $this->reset('password');
        $this->must_change = true;
        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    public function saveRates(UpdateEmployeeRates $updateRates): void
    {
        $user = $this->employee();

        $updateRates->handle(Auth::user(), $user, $this->rates);

        $this->fillRates($user);
        Flux::toast(variant: 'success', text: __('Hourly rates saved.'));
    }

    public function delete(DeleteEmployee $deleteEmployee): void
    {
        $deleteEmployee->handle(Auth::user(), $this->employee());

        $this->redirect(route('employees.index'), navigate: true);
    }

    public function with(): array
    {
        $actor = Auth::user();
        $user = $this->employee();
        $this->authorizeView($user);

        $authorizer = app(Authorizer::class);
        $canUpdate = $this->canUpdate($user);
        $manageable = $canUpdate ? app(UpdateEmployee::class)->manageableStoreIds($actor, $user) : [];

        $assignableRoles = Role::query()->where('is_system', false)->orderBy('name')->get();
        $stores = $user->stores()->orderBy('name')->get();

        $storeRows = $stores->map(function ($store) use ($authorizer, $actor, $assignableRoles, $manageable): array {
            $current = (int) ($this->storeRoles[$store->id] ?? 0);

            return [
                'store' => $store,
                'manageable' => in_array($store->id, $manageable, true),
                'accessible' => $authorizer->allows($actor, 'stores.view', $store),
                'roles' => $assignableRoles->filter(
                    fn (Role $role): bool => $role->id === $current || $authorizer->canAssignRole($actor, $role, $store),
                )->values(),
            ];
        });

        return [
            'employee' => $user,
            'canUpdate' => $canUpdate,
            'canDelete' => $authorizer->allows($actor, 'employees.delete') && $authorizer->canActOnUser($actor, 'employees.delete', $user),
            'isActorOwner' => $authorizer->isOwner($actor),
            'storeRows' => $storeRows,
            'shiftTypes' => $canUpdate ? app(UpdateEmployeeRates::class)->eligibleShiftTypes($actor, $user) : collect(),
            'defaultPassword' => PasswordDefaults::DEFAULT_PASSWORD,
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-10 p-4">
    <div class="flex items-center gap-4">
        <x-user-avatar :user="$employee" size="lg" />
        <div class="flex-1">
            <flux:heading size="xl">{{ $employee->name }}</flux:heading>
            <flux:subheading>{{ $employee->email ?? $employee->employee_code }}</flux:subheading>
        </div>
        <flux:button :href="route('employees.index')" wire:navigate variant="filled">{{ __('Back to employees') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:heading size="lg">{{ __('Identity') }}</flux:heading>

        <x-employees.identity-fields :disabled="! $canUpdate" />

        <div class="space-y-3">
            <flux:checkbox wire:model="is_active" :label="__('Active account')" :disabled="! $canUpdate" />
            @error('is_active') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

            @if ($isActorOwner)
                <flux:checkbox wire:model="is_owner" :label="__('Owner')" :description="__('Full access to the whole application, for all stores.')" :disabled="! $canUpdate" />
            @elseif ($is_owner)
                <flux:badge color="red">{{ __('Owner') }}</flux:badge>
            @endif
            @error('is_owner') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        </div>

        <flux:heading size="lg">{{ __('Stores and roles') }}</flux:heading>

        @forelse ($storeRows as $row)
            <div class="grid items-end gap-4 sm:grid-cols-2" wire:key="store-{{ $row['store']->id }}">
                <div>
                    @if ($row['accessible'])
                        <flux:link :href="route('stores.edit', $row['store'])" wire:navigate class="font-medium">{{ $row['store']->name }}</flux:link>
                    @else
                        <flux:text class="font-medium">{{ $row['store']->name }}</flux:text>
                    @endif
                    <flux:text class="text-sm">
                        @if ($row['store']->pivot->staff_code) {{ __('Staff code') }}: {{ $row['store']->pivot->staff_code }} @endif
                        @if ($row['store']->pivot->hire_date) {{ __('Hired on') }} {{ $row['store']->pivot->hire_date->format('Y-m-d') }} @endif
                    </flux:text>
                </div>
                @if ($row['manageable'])
                    <flux:select wire:model="storeRoles.{{ $row['store']->id }}" :label="__('Role')">
                        <flux:select.option value="">{{ __('Unchanged') }}</flux:select.option>
                        @foreach ($row['roles'] as $role)
                            <flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('store_roles.'.$row['store']->id) <flux:text class="text-red-500 sm:col-span-2">{{ $message }}</flux:text> @enderror
                @endif
            </div>
        @empty
            <flux:text>{{ __('This employee does not belong to any store.') }}</flux:text>
        @endforelse

        <flux:heading size="lg">{{ __('HR details') }}</flux:heading>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="hr.gender" :label="__('Gender')" :disabled="! $canUpdate">
                <flux:select.option value="">—</flux:select.option>
                <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="hr.birth_date" :label="__('Birth date')" type="date" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.phone" :label="__('Phone')" type="tel" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.mobile_phone" :label="__('Mobile phone')" type="tel" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.postal_code" :label="__('Postal code')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.address" :label="__('Address')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.tax_classification" :label="__('Tax classification')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.education" :label="__('Education')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.guarantor_name" :label="__('Guarantor name')" type="text" :disabled="! $canUpdate" />
            <flux:input wire:model="hr.guarantor_phone" :label="__('Guarantor phone')" type="tel" :disabled="! $canUpdate" />
        </div>
        @foreach (array_keys($hr) as $field)
            @error($field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        @endforeach

        @if ($canUpdate)
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" data-test="save-employee-button">{{ __('Save') }}</flux:button>
            </div>
        @endif
    </form>

    @if ($canUpdate)
        <div class="space-y-4" data-test="password-section">
            <flux:heading size="lg">{{ __('Password') }}</flux:heading>

            <div>
                <flux:button wire:click="resetPassword" wire:confirm="{{ __('Reset the password to :password and sign the employee out everywhere?', ['password' => $defaultPassword]) }}" data-test="reset-password-button">
                    {{ __('Reset to :password', ['password' => $defaultPassword]) }}
                </flux:button>
            </div>

            <form wire:submit="setPassword" class="space-y-4">
                <flux:input wire:model="password" :label="__('New password')" type="password" viewable autocomplete="new-password" />
                <flux:checkbox wire:model="must_change" :label="__('Ask the employee to change it at next sign-in')" />
                <flux:button type="submit" data-test="set-password-button">{{ __('Set password') }}</flux:button>
            </form>
        </div>

        @if ($shiftTypes->isNotEmpty())
            <form wire:submit="saveRates" class="space-y-4" data-test="rates-section">
                <flux:heading size="lg">{{ __('Personal hourly rates') }}</flux:heading>
                <flux:subheading>{{ __('Leave empty to use the shift type rate.') }}</flux:subheading>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($shiftTypes as $type)
                        <div wire:key="rate-{{ $type->id }}">
                            <flux:input wire:model="rates.{{ $type->id }}" :label="$type->name" :placeholder="$type->hourly_rate" type="number" step="0.01" min="0" />
                            @error('rates.'.$type->id) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                        </div>
                    @endforeach
                </div>

                <flux:button type="submit" data-test="save-rates-button">{{ __('Save rates') }}</flux:button>
            </form>
        @endif
    @endif

    @if ($canDelete)
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete employee') }}</flux:heading>
            <flux:modal.trigger name="confirm-employee-deletion">
                <flux:button variant="danger" data-test="delete-employee-button">{{ __('Delete employee') }}</flux:button>
            </flux:modal.trigger>

            <flux:modal name="confirm-employee-deletion" :show="$errors->has('delete')" focusable class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete this employee?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('The account is anonymized: personal data is erased and the person can no longer sign in. The shift history is kept, without the name.') }}
                        </flux:subheading>
                    </div>

                    @error('delete') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-employee-button">{{ __('Delete employee') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endif
</section>
