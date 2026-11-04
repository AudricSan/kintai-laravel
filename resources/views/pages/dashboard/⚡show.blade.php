<?php

use App\Actions\Dashboard\ActivateDashboardLayout;
use App\Actions\Dashboard\ClearRoleDashboardLayout;
use App\Actions\Dashboard\DeleteDashboardLayout;
use App\Actions\Dashboard\ResetDashboardLayout;
use App\Actions\Dashboard\SaveDashboardLayout;
use App\Actions\Dashboard\SaveRoleDashboardLayout;
use App\Authorization\Authorizer;
use App\Dashboard\DashboardLayoutResolver;
use App\Dashboard\DashboardPlacement;
use App\Dashboard\DashboardWidget;
use App\Dashboard\WidgetSize;
use App\Models\DashboardLayout;
use App\Models\Role;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** Tableau affiché : `manager` ou `employee` */
    #[Locked]
    public string $dashboard = DashboardWidget::EMPLOYEE;

    public bool $editing = false;

    /** @var list<array{widget: string, size: string}> Disposition en cours d'édition */
    public array $draft = [];

    public string $name = '';

    /** Disposition personnelle en cours de modification (null = nouvelle) */
    public ?int $editingId = null;

    /** Disposition personnelle à supprimer (fenêtre de confirmation) */
    public ?int $deleteId = null;

    /** Rôle visé par « disposition du rôle » (Owner) */
    public string $roleId = '';

    /**
     * @param  string  $audience  `auto` : tableau gérant pour un gérant ou un Owner, tableau employé sinon.
     */
    public function mount(string $audience = 'auto'): void
    {
        $this->dashboard = $audience === DashboardWidget::EMPLOYEE || ! app(Authorizer::class)->isManager(Auth::user())
            ? DashboardWidget::EMPLOYEE
            : DashboardWidget::MANAGER;
    }

    public function startEditing(?int $layoutId = null): void
    {
        $user = Auth::user();
        $resolver = app(DashboardLayoutResolver::class);

        $layout = $layoutId === null
            ? null
            : DashboardLayout::query()->personal($user, $this->dashboard)->findOrFail($layoutId);

        $placements = $layout === null
            ? $resolver->resolve($user, $this->dashboard)
            : $resolver->placements($user, $this->dashboard, $layout->layout);

        $this->draft = array_map(
            fn (DashboardPlacement $placement): array => ['widget' => $placement->widget->key, 'size' => $placement->size->value],
            $placements,
        );
        $this->editingId = $layout?->id;
        $this->name = $layout?->name ?? '';
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->draft = [];
        $this->editingId = null;
        $this->name = '';
        $this->resetErrorBag();
    }

    public function addWidget(string $key): void
    {
        $available = array_map(
            fn (DashboardWidget $widget): string => $widget->key,
            app(DashboardLayoutResolver::class)->available(Auth::user(), $this->dashboard),
        );

        if (! in_array($key, $available, true) || in_array($key, array_column($this->draft, 'widget'), true)) {
            return;
        }

        $widget = app(\App\Dashboard\DashboardWidgetRegistry::class)->get($key);
        $this->draft[] = ['widget' => $key, 'size' => ($widget?->size ?? WidgetSize::Medium)->value];
    }

    public function removeWidget(int $index): void
    {
        unset($this->draft[$index]);
        $this->draft = array_values($this->draft);
    }

    public function moveUp(int $index): void
    {
        $this->swap($index, $index - 1);
    }

    public function moveDown(int $index): void
    {
        $this->swap($index, $index + 1);
    }

    private function swap(int $from, int $to): void
    {
        if (! isset($this->draft[$from], $this->draft[$to])) {
            return;
        }

        [$this->draft[$from], $this->draft[$to]] = [$this->draft[$to], $this->draft[$from]];
    }

    public function saveLayout(): void
    {
        $this->resetErrorBag();

        $existing = $this->editingId === null
            ? null
            : DashboardLayout::query()->personal(Auth::user(), $this->dashboard)->findOrFail($this->editingId);

        app(SaveDashboardLayout::class)->handle(Auth::user(), $this->dashboard, $this->name, $this->draft, $existing);

        $this->cancelEditing();
        Flux::toast(variant: 'success', text: __('Layout saved.'));
    }

    public function activateLayout(int $id): void
    {
        $layout = DashboardLayout::query()->personal(Auth::user(), $this->dashboard)->findOrFail($id);

        app(ActivateDashboardLayout::class)->handle(Auth::user(), $layout);
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = DashboardLayout::query()->personal(Auth::user(), $this->dashboard)->findOrFail($id)->id;
        Flux::modal('delete-layout')->show();
    }

    public function deleteLayout(): void
    {
        $layout = DashboardLayout::query()->personal(Auth::user(), $this->dashboard)->findOrFail($this->deleteId);

        app(DeleteDashboardLayout::class)->handle(Auth::user(), $layout);

        if ($this->editingId === $layout->id) {
            $this->cancelEditing();
        }
        $this->deleteId = null;
        Flux::modal('delete-layout')->close();
        Flux::toast(variant: 'success', text: __('Layout deleted.'));
    }

    public function useDefaults(): void
    {
        app(ResetDashboardLayout::class)->handle(Auth::user(), $this->dashboard);

        $this->cancelEditing();
        Flux::toast(variant: 'success', text: __('Default layout restored.'));
    }

    public function saveRoleLayout(): void
    {
        $this->resetErrorBag();

        app(SaveRoleDashboardLayout::class)->handle(Auth::user(), Role::query()->findOrFail((int) $this->roleId), $this->dashboard, $this->draft);

        Flux::toast(variant: 'success', text: __('Role layout saved.'));
    }

    public function clearRoleLayout(): void
    {
        app(ClearRoleDashboardLayout::class)->handle(Auth::user(), Role::query()->findOrFail((int) $this->roleId), $this->dashboard);

        Flux::toast(variant: 'success', text: __('Role layout removed.'));
    }

    public function with(): array
    {
        $user = Auth::user();
        $resolver = app(DashboardLayoutResolver::class);
        $registry = app(\App\Dashboard\DashboardWidgetRegistry::class);
        $drafted = array_column($this->draft, 'widget');

        return [
            'placements' => $resolver->resolve($user, $this->dashboard),
            'isManager' => app(Authorizer::class)->isManager($user),
            'layouts' => DashboardLayout::query()->personal($user, $this->dashboard)->orderBy('name')->get(),
            'addable' => array_values(array_filter(
                $resolver->available($user, $this->dashboard),
                fn (DashboardWidget $widget): bool => ! in_array($widget->key, $drafted, true),
            )),
            'registry' => $registry,
            'sizes' => WidgetSize::cases(),
            'canManageRoles' => Gate::allows('manage-roles'),
            'roles' => Gate::allows('manage-roles') ? Role::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" data-test="dashboard-title">
            {{ $dashboard === 'manager' ? __('Dashboard') : __('My dashboard') }}
        </flux:heading>

        <div class="flex flex-wrap gap-2">
            @if ($isManager)
                @if ($dashboard === 'manager')
                    <flux:button :href="route('dashboard.employee')" wire:navigate icon="user" data-test="switch-dashboard">{{ __('My dashboard') }}</flux:button>
                @else
                    <flux:button :href="route('dashboard')" wire:navigate icon="squares-2x2" data-test="switch-dashboard">{{ __('Manager dashboard') }}</flux:button>
                @endif
            @endif
            @unless ($editing)
                <flux:button wire:click="startEditing" icon="adjustments-horizontal" data-test="customize">{{ __('Customize') }}</flux:button>
            @endunless
        </div>
    </div>

    @if ($editing)
        <div class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="customize-panel">
            <flux:heading size="lg">{{ __('Customize the dashboard') }}</flux:heading>

            <div class="space-y-2">
                @forelse ($draft as $index => $entry)
                    @php($widget = $registry->get($entry['widget']))
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="draft-{{ $entry['widget'] }}" data-test="draft-widget">
                        <span class="min-w-40 flex-1 font-medium">{{ __($widget?->label ?? $entry['widget']) }}</span>

                        @if ($widget?->resizable)
                            <flux:select wire:model.live="draft.{{ $index }}.size" class="max-w-40" :aria-label="__('Size')">
                                @foreach ($sizes as $size)
                                    <flux:select.option :value="$size->value">{{ __($size->label()) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif

                        <flux:button size="sm" icon="arrow-up" wire:click="moveUp({{ $index }})" :disabled="$index === 0" :aria-label="__('Move up')" data-test="move-up" />
                        <flux:button size="sm" icon="arrow-down" wire:click="moveDown({{ $index }})" :disabled="$index === count($draft) - 1" :aria-label="__('Move down')" data-test="move-down" />
                        <flux:button size="sm" icon="x-mark" variant="danger" wire:click="removeWidget({{ $index }})" :aria-label="__('Remove')" data-test="remove-widget" />
                    </div>
                @empty
                    <flux:text>{{ __('No widget in this layout.') }}</flux:text>
                @endforelse
                <flux:error name="layout" />
            </div>

            @if ($addable !== [])
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm">{{ __('Add a widget') }}</span>
                    @foreach ($addable as $widget)
                        <flux:button size="sm" icon="plus" wire:click="addWidget('{{ $widget->key }}')" wire:key="add-{{ $widget->key }}" data-test="add-widget-{{ $widget->key }}">{{ __($widget->label) }}</flux:button>
                    @endforeach
                </div>
            @endif

            <form wire:submit="saveLayout" class="flex flex-wrap items-end gap-2">
                <flux:input wire:model="name" :label="__('Layout name')" class="max-w-xs" data-test="layout-name" />
                <flux:button variant="primary" type="submit" data-test="save-layout">{{ $editingId === null ? __('Save as new layout') : __('Save changes') }}</flux:button>
                <flux:button type="button" wire:click="cancelEditing">{{ __('Cancel') }}</flux:button>
            </form>
            <flux:error name="name" />

            <div class="space-y-2">
                <flux:heading>{{ __('My layouts') }}</flux:heading>
                @forelse ($layouts as $layout)
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="layout-{{ $layout->id }}" data-test="saved-layout">
                        <span class="min-w-40 flex-1">{{ $layout->name }} @if ($layout->is_active)<flux:badge color="green" data-test="active-layout">{{ __('Active') }}</flux:badge>@endif</span>
                        @unless ($layout->is_active)
                            <flux:button size="sm" wire:click="activateLayout({{ $layout->id }})" data-test="activate-layout">{{ __('Activate') }}</flux:button>
                        @endunless
                        <flux:button size="sm" wire:click="startEditing({{ $layout->id }})" data-test="edit-layout">{{ __('Edit') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="confirmDelete({{ $layout->id }})" data-test="delete-layout">{{ __('Delete') }}</flux:button>
                    </div>
                @empty
                    <flux:text size="sm">{{ __('No saved layout yet.') }}</flux:text>
                @endforelse
                <flux:button size="sm" wire:click="useDefaults" data-test="use-defaults">{{ __('Use the default layout') }}</flux:button>
            </div>

            @if ($canManageRoles)
                <div class="space-y-2" data-test="role-layouts">
                    <flux:heading>{{ __('Layout imposed on a role') }}</flux:heading>
                    <flux:text size="sm">{{ __('The widgets above become the default dashboard of every user holding the role who has no active personal layout.') }}</flux:text>
                    <div class="flex flex-wrap items-end gap-2">
                        <flux:select wire:model="roleId" :label="__('Role')" class="max-w-xs" data-test="role-select">
                            <flux:select.option value="">{{ __('Choose a role') }}</flux:select.option>
                            @foreach ($roles as $role)
                                <flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button wire:click="saveRoleLayout" :disabled="$roleId === ''" data-test="save-role-layout">{{ __('Apply to the role') }}</flux:button>
                        <flux:button variant="ghost" wire:click="clearRoleLayout" :disabled="$roleId === ''" data-test="clear-role-layout">{{ __('Remove the role layout') }}</flux:button>
                    </div>
                </div>
            @endif
        </div>

        <flux:modal name="delete-layout" class="max-w-lg">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Delete this layout?') }}</flux:heading>
                <flux:text>{{ __('The saved layout will be removed. This cannot be undone.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="deleteLayout" data-test="confirm-delete-layout">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    @if ($placements === [])
        <flux:text data-test="no-widgets">{{ __('No widget to display.') }}</flux:text>
    @else
        <div class="grid grid-cols-12 gap-4" data-test="widget-grid">
            @foreach ($placements as $placement)
                <div class="{{ $placement->size->cssClass() }}" wire:key="cell-{{ $placement->widget->key }}-{{ $placement->size->value }}" data-test="widget-cell">
                    <livewire:widgets::frame :widget="$placement->widget->key" :key="'frame-'.$placement->widget->key" />
                </div>
            @endforeach
        </div>
    @endif
</section>
