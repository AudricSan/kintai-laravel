<?php

use App\Actions\Bundles\DisableBundle;
use App\Actions\Bundles\EnableBundle;
use App\Bundles\BundleManager;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Contract;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bundles')] class extends Component {
    /** Bundle visé par la modale de confirmation. */
    #[Locked]
    public string $targetSlug = '';

    /** `enable` ou `disable`. */
    #[Locked]
    public string $targetAction = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);
    }

    public function confirm(string $slug, string $action): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        if (! in_array($action, ['enable', 'disable'], true) || app(BundleManager::class)->find($slug) === null) {
            return;
        }

        $this->resetErrorBag();
        $this->targetSlug = $slug;
        $this->targetAction = $action;

        Flux::modal('confirm-bundle-change')->show();
    }

    public function applyChange(EnableBundle $enable, DisableBundle $disable): void
    {
        $slug = $this->targetSlug;
        $action = $this->targetAction;

        $this->targetSlug = '';
        $this->targetAction = '';

        if ($slug === '' || $action === '') {
            return;
        }

        try {
            $changed = $action === 'enable' ? $enable->handle(Auth::user(), $slug) : $disable->handle(Auth::user(), $slug);
        } catch (ValidationException $e) {
            $this->addError('bundle', $e->errors()['bundle'][0] ?? __('The bundle could not be changed.'));
            Flux::modal('confirm-bundle-change')->close();

            return;
        }

        Flux::modal('confirm-bundle-change')->close();

        if ($changed) {
            // Les bundles se branchent au démarrage de l'application : on recharge la page pour que le menu suive.
            session()->flash('bundle-status', $action === 'enable' ? __('Bundle enabled.') : __('Bundle disabled. Its data is kept.'));
            $this->redirectRoute('bundles.index');
        }
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $manager = app(BundleManager::class);
        $rows = [];

        foreach ($manager->installed() as $slug => $bundle) {
            $rows[] = [
                'slug' => $slug,
                'name' => __($bundle->name()),
                'version' => $bundle->version(),
                'description' => __($bundle->description()),
                'required' => $bundle->requiredContract(),
                'compatible' => $manager->isCompatible($bundle),
                'enabled' => $manager->isEnabled($slug),
            ];
        }

        $target = $this->targetSlug === '' ? null : $manager->find($this->targetSlug);

        return [
            'bundles' => $rows,
            'contractVersion' => Contract::VERSION,
            'targetName' => $target === null ? '' : __($target->name()),
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div>
        <flux:heading size="xl">{{ __('Bundles') }}</flux:heading>
        <flux:subheading>{{ __('Bundles add optional features to Kintai. Enable the ones you need; then choose, store by store, which features are active. Disabling a bundle hides it everywhere but deletes none of its data.') }}</flux:subheading>
    </div>

    @if (session('bundle-status'))
        <flux:callout variant="success" icon="check-circle" data-test="bundle-status">
            <flux:callout.text>{{ session('bundle-status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @error('bundle')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="bundle-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <flux:text size="sm" data-test="contract-version">{{ __('Kintai contract version: :version', ['version' => $contractVersion]) }}</flux:text>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Bundle') }}</flux:table.column>
            <flux:table.column>{{ __('Version') }}</flux:table.column>
            <flux:table.column>{{ __('Required contract') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($bundles as $bundle)
                <flux:table.row :key="$bundle['slug']" data-test="bundle-row" data-slug="{{ $bundle['slug'] }}">
                    <flux:table.cell>
                        <div class="font-medium">{{ $bundle['name'] }} <code class="text-xs text-zinc-500">{{ $bundle['slug'] }}</code></div>
                        <flux:text size="sm">{{ $bundle['description'] }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $bundle['version'] }}</flux:table.cell>
                    <flux:table.cell><code>{{ $bundle['required'] }}</code></flux:table.cell>
                    <flux:table.cell>
                        @if ($bundle['enabled'])
                            <flux:badge size="sm" color="green" inset="top bottom" data-test="bundle-enabled">{{ __('Enabled') }}</flux:badge>
                        @elseif (! $bundle['compatible'])
                            <flux:badge size="sm" color="red" inset="top bottom" data-test="bundle-incompatible">{{ __('Incompatible') }}</flux:badge>
                        @else
                            <flux:badge size="sm" inset="top bottom" data-test="bundle-disabled">{{ __('Disabled') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end">
                            @if ($bundle['enabled'])
                                <flux:button size="sm" wire:click="confirm('{{ $bundle['slug'] }}', 'disable')" data-test="disable-bundle">{{ __('Disable') }}</flux:button>
                            @else
                                <flux:button size="sm" variant="primary" wire:click="confirm('{{ $bundle['slug'] }}', 'enable')" data-test="enable-bundle">{{ __('Enable') }}</flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" data-test="no-bundles">{{ __('No bundle is installed.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="confirm-bundle-change" class="max-w-lg">
        <div class="space-y-6">
            <div>
                @if ($targetAction === 'disable')
                    <flux:heading size="lg">{{ __('Disable :name?', ['name' => $targetName]) }}</flux:heading>
                    <flux:subheading>{{ __('The bundle disappears from the menu, the dashboards, the roles and the stores. None of its data is deleted: enabling it again brings everything back.') }}</flux:subheading>
                @else
                    <flux:heading size="lg">{{ __('Enable :name?', ['name' => $targetName]) }}</flux:heading>
                    <flux:subheading>{{ __('The bundle appears in the menu, the dashboards, the roles and the store features. Grant its permissions to roles from the Roles screen.') }}</flux:subheading>
                @endif
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button :variant="$targetAction === 'disable' ? 'danger' : 'primary'" wire:click="applyChange" data-test="confirm-bundle-change">
                    {{ $targetAction === 'disable' ? __('Disable') : __('Enable') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
