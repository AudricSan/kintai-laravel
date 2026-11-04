<?php

use App\Actions\Roles\DeleteRole;
use App\Models\Role;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roles')] class extends Component {
    public function mount(): void
    {
        abort_unless(Gate::allows('manage-roles'), 403);
    }

    /** Rôle visé par la modale de confirmation ouverte. */
    #[Locked]
    public int $pendingRoleId = 0;

    public function confirmDelete(int $roleId): void
    {
        $this->pendingRoleId = Role::query()->findOrFail($roleId)->id;
        Flux::modal('confirm-role-deletion')->show();
    }

    public function deletePending(DeleteRole $deleteRole): void
    {
        $this->delete($this->pendingRoleId, $deleteRole);
        Flux::modal('confirm-role-deletion')->close();
    }

    public function delete(int $roleId, DeleteRole $deleteRole): void
    {
        $deleteRole->handle(Auth::user(), Role::query()->findOrFail($roleId));

        Flux::toast(variant: 'success', text: __('Role deleted.'));
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-roles'), 403);

        return [
            'roles' => Role::query()->withCount('assignments')->orderByDesc('is_system')->orderBy('name')->get(),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Roles') }}</flux:heading>
            <flux:subheading>{{ __('Roles group permissions that are granted per store or for all stores.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('roles.create')" wire:navigate data-test="new-role-button">
            {{ __('New role') }}
        </flux:button>
    </div>

    @error('role')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="role-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Manager') }}</flux:table.column>
            <flux:table.column>{{ __('Holders') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($roles as $role)
                <flux:table.row :key="$role->id" data-test="role-row">
                    <flux:table.cell>
                        <span class="me-2 inline-block size-3 rounded-full align-middle" style="background-color: {{ $role->color }}"></span>
                        @if ($role->is_system)
                            <flux:link :href="route('roles.edit', $role)" wire:navigate>{{ $role->name }}</flux:link>
                            <flux:badge size="sm" color="red" inset="top bottom">{{ __('System') }}</flux:badge>
                        @else
                            <flux:link :href="route('roles.edit', $role)" wire:navigate>{{ $role->name }}</flux:link>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $role->description }}</flux:table.cell>
                    <flux:table.cell>{{ $role->is_manager ? __('Yes') : __('No') }}</flux:table.cell>
                    <flux:table.cell>{{ $role->assignments_count }}</flux:table.cell>
                    <flux:table.cell>
                        @unless ($role->is_system)
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete({{ $role->id }})" data-test="delete-role-button">
                                {{ __('Delete') }}
                            </flux:button>
                        @endunless
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirm-role-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this role?') }}</flux:heading>
                <flux:subheading>{{ __('Holders lose the permissions of this role. This cannot be undone.') }}</flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deletePending" data-test="confirm-delete-role-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
