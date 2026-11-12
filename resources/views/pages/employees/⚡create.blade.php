<?php

use App\Actions\Employees\CreateEmployee;
use App\Authorization\Authorizer;
use App\Authorization\PasswordDefaults;
use App\Concerns\EmployeeValidationRules;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New employee')] class extends Component {
    use EmployeeValidationRules;

    public string $last_name = '';

    public string $first_name = '';

    public string $furigana_last_name = '';

    public string $furigana_first_name = '';

    public string $name = '';

    public string $email = '';

    public string $employee_code = '';

    public string $password = '';

    public string $color = '#3B82F6';

    public string $store_id = '';

    public string $role_id = '';

    public bool $is_owner = false;

    public function mount(): void
    {
        abort_unless(Gate::allows('employees.create'), 403);

        // Un acteur limité à un seul magasin n'a qu'un choix : on le présélectionne.
        $stores = $this->stores;
        if ($stores->count() === 1) {
            $this->store_id = (string) $stores->first()->id;
        }

        $this->role_id = (string) (Role::query()->where('slug', 'employee')->value('id') ?? '');
    }

    /**
     * Magasins où l'acteur peut créer des employés.
     *
     * @return \Illuminate\Support\Collection<int, Store>
     */
    #[Computed]
    public function stores()
    {
        $ids = app(Authorizer::class)->storeIdsFor(Auth::user(), 'employees.create');

        return Store::query()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
    }

    /**
     * Rôles non système que l'acteur peut attribuer dans le magasin choisi.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    #[Computed]
    public function roles()
    {
        $authorizer = app(Authorizer::class);
        $store = $this->store_id === '' ? null : $this->stores->firstWhere('id', (int) $this->store_id);

        if ($store === null) {
            return collect();
        }

        return Role::query()->where('is_system', false)->orderBy('name')->get()
            ->filter(fn (Role $role): bool => $authorizer->canAssignRole(Auth::user(), $role, $store))
            ->values();
    }

    public function updatedEmployeeCode(): void
    {
        $this->employee_code = Str::upper(trim($this->employee_code));
        $this->validateOnly('employee_code', $this->identityRules());
    }

    public function updatedEmail(): void
    {
        $this->email = Str::lower(trim($this->email));
        $this->validateOnly('email', $this->identityRules());
    }

    /**
     * Un changement de magasin peut rendre le rôle choisi inattribuable.
     */
    public function updatedStoreId(): void
    {
        if (! $this->roles->contains('id', (int) $this->role_id)) {
            $this->role_id = (string) ($this->roles->firstWhere('slug', 'employee')?->id ?? '');
        }
    }

    public function save(CreateEmployee $createEmployee): void
    {
        $user = $createEmployee->handle(Auth::user(), [
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'furigana_last_name' => $this->furigana_last_name,
            'furigana_first_name' => $this->furigana_first_name,
            'name' => $this->name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'password' => $this->password,
            'color' => $this->color,
            'store_id' => $this->store_id === '' ? null : $this->store_id,
            'role_id' => $this->role_id === '' ? null : $this->role_id,
            'is_owner' => $this->is_owner,
        ], 'form');

        $this->redirect(route('employees.edit', $user), navigate: true);
    }

    public function with(): array
    {
        return [
            'isActorOwner' => app(Authorizer::class)->isOwner(Auth::user()),
            'defaultPassword' => PasswordDefaults::DEFAULT_PASSWORD,
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('New employee') }}</flux:heading>
        <flux:subheading>{{ __('The employee is asked to change the default password at first sign-in.') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-employees.identity-fields />

        <flux:input wire:model="password" :label="__('Password')" :description="__('Left empty, the password is :password and must be changed at first sign-in.', ['password' => $defaultPassword])" type="password" viewable autocomplete="new-password" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="store_id" :label="__('Store')">
                @if ($this->stores->count() !== 1)
                    <flux:select.option value="">{{ __('No store') }}</flux:select.option>
                @endif
                @foreach ($this->stores as $store)
                    <flux:select.option :value="$store->id">{{ $store->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="role_id" :label="__('Role')">
                @foreach ($this->roles as $role)
                    <flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($isActorOwner)
            <flux:checkbox wire:model="is_owner" :label="__('Owner')" :description="__('Full access to the whole application, for all stores.')" />
        @endif

        <div class="flex justify-end gap-2">
            <flux:button :href="route('employees.index')" wire:navigate variant="filled">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" data-test="create-employee-button">{{ __('Create employee') }}</flux:button>
        </div>
    </form>
</section>
