<?php

use App\Actions\Roles\ManageRoleHolders;
use App\Actions\Roles\SaveRole;
use Kintai\Contracts\Authorization\PermissionRegistry;
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

new #[Title('Role')] class extends Component {
    #[Locked]
    public ?int $roleId = null;

    public string $name = '';

    public string $description = '';

    public string $color = '#6B7280';

    public bool $is_manager = false;

    /** @var list<string> clés cochées (une case par clé du registre) */
    public array $permissions = [];

    /** @var list<string> clés cochées qui ignorent la portée magasin */
    public array $allStores = [];

    public string $holder_user = '';

    /** Vide = global. */
    public string $holder_store = '';

    public function mount(?Role $role = null): void
    {
        abort_unless(Gate::allows('manage-roles'), 403);

        if ($role !== null) {
            $this->roleId = $role->id;
            $this->name = $role->name;
            $this->description = $role->description ?? '';
            $this->color = $role->color;
            $this->is_manager = $role->is_manager;

            $registry = app(PermissionRegistry::class);
            $rows = $role->permissions()->orderBy('permission')->get()->filter(fn ($row) => $registry->has($row->permission));
            $this->permissions = $rows->pluck('permission')->values()->all();
            $this->allStores = $rows->where('all_stores', true)->pluck('permission')->values()->all();
        }
    }

    private function role(): ?Role
    {
        return $this->roleId === null ? null : Role::query()->findOrFail($this->roleId);
    }

    public function save(SaveRole $saveRole): void
    {
        $isNew = $this->roleId === null;

        $role = $saveRole->handle(Auth::user(), $this->role(), [
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'is_manager' => $this->is_manager,
            'permissions' => $this->permissions,
            'all_stores' => $this->allStores,
        ]);

        if ($isNew) {
            $this->redirect(route('roles.edit', $role), navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: __('Role saved.'));
    }

    public function addHolder(ManageRoleHolders $holders): void
    {
        $this->resetErrorBag();

        $role = $this->role();
        abort_if($role === null, 404);

        $holders->add(
            Auth::user(),
            $role,
            (int) $this->holder_user,
            $this->holder_store === '' ? null : (int) $this->holder_store,
        );

        $this->reset('holder_user', 'holder_store');
        Flux::toast(variant: 'success', text: __('Holder added.'));
    }

    /** Attribution visée par la modale de confirmation ouverte. */
    #[Locked]
    public int $pendingAssignmentId = 0;

    public function confirmRemoveHolder(int $assignmentId): void
    {
        $role = $this->role();
        abort_if($role === null, 404);

        $this->pendingAssignmentId = $role->assignments()->findOrFail($assignmentId)->id;
        Flux::modal('confirm-holder-removal')->show();
    }

    public function removePendingHolder(ManageRoleHolders $holders): void
    {
        $this->removeHolder($this->pendingAssignmentId, $holders);
        Flux::modal('confirm-holder-removal')->close();
    }

    public function removeHolder(int $assignmentId, ManageRoleHolders $holders): void
    {
        $this->resetErrorBag();

        $role = $this->role();
        abort_if($role === null, 404);

        $holders->remove(Auth::user(), $role, $assignmentId);

        Flux::toast(variant: 'success', text: __('Holder removed.'));
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-roles'), 403);

        $role = $this->role();
        $selectedUser = $this->holder_user === '' ? null : User::query()->find((int) $this->holder_user);

        return [
            'role' => $role,
            'categories' => app(PermissionRegistry::class)->categories(),
            'assignments' => $role === null ? collect() : RoleAssignment::query()
                ->with(['user', 'store'])
                ->where('role_id', $role->id)
                ->orderBy('id')
                ->get()
                ->filter(fn (RoleAssignment $assignment): bool => $assignment->user !== null),
            'candidates' => $role === null ? collect() : User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'candidateStores' => $selectedUser?->stores()->orderBy('name')->get() ?? collect(),
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-10 p-4">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ $role ? __('Edit role') : __('New role') }}</flux:heading>
        <flux:button :href="route('roles.index')" wire:navigate variant="filled">{{ __('Back to roles') }}</flux:button>
    </div>

    @if ($role?->is_system)
        <flux:callout icon="lock-closed" data-test="system-role-notice">
            <flux:callout.text>{{ __('The Owner role is built in: only its holders can be managed here.') }}</flux:callout.text>
        </flux:callout>
    @else
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" :label="__('Name')" type="text" required maxlength="100" />
        <flux:textarea wire:model="description" :label="__('Description')" rows="2" />
        <flux:input wire:model="color" :label="__('Color')" type="color" />
        <flux:checkbox wire:model="is_manager" :label="__('Manager role')" :description="__('Shows the manager navigation.')" />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Permissions') }}</flux:heading>

            @foreach ($categories as $category => $actions)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="category-{{ $category }}">
                    <flux:heading>{{ __(Str::headline($category)) }}</flux:heading>
                    <div class="mt-3 space-y-2">
                        @foreach ($actions as $action)
                            @php($key = $category.'.'.$action)
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-1" wire:key="permission-{{ $key }}">
                                <div class="w-40">
                                    <flux:checkbox wire:model.live="permissions" value="{{ $key }}" :label="__(Str::headline($action))" />
                                </div>
                                @if (in_array($key, $permissions, true))
                                    <flux:checkbox wire:model="allStores" value="{{ $key }}" :label="__('All stores')" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-role-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
    @endif

    @if ($role)
        <div class="space-y-4" data-test="holders-section">
            <flux:heading size="lg">{{ __('Holders') }}</flux:heading>

            @error('holder') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('User') }}</flux:table.column>
                    <flux:table.column>{{ __('Scope') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($assignments as $assignment)
                        <flux:table.row :key="$assignment->id">
                            <flux:table.cell>{{ $assignment->user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $assignment->store?->name ?? __('Global') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" variant="danger" wire:click="confirmRemoveHolder({{ $assignment->id }})" data-test="remove-holder-button">
                                    {{ __('Remove') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3">{{ __('No holder yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <form wire:submit="addHolder" class="grid items-end gap-4 sm:grid-cols-3">
                <flux:select wire:model.live="holder_user" :label="__('User')">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($candidates as $candidate)
                        <flux:select.option :value="$candidate->id">{{ $candidate->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="holder_store" :label="__('Scope')">
                    <flux:select.option value="">{{ __('Global') }}</flux:select.option>
                    @unless ($role->is_system)
                        @foreach ($candidateStores as $store)
                            <flux:select.option :value="$store->id">{{ $store->name }}</flux:select.option>
                        @endforeach
                    @endunless
                </flux:select>
                <flux:button type="submit" data-test="add-holder-button">{{ __('Add') }}</flux:button>
            </form>
            @error('holder_user') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
            @error('holder_store') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

        <flux:modal name="confirm-holder-removal" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Remove this holder?') }}</flux:heading>
                    <flux:subheading>{{ __('The user loses this role in this scope.') }}</flux:subheading>
                </div>
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="removePendingHolder" data-test="confirm-remove-holder-button">{{ __('Remove') }}</flux:button>
                </div>
            </div>
        </flux:modal>
        </div>
    @endif
</section>
