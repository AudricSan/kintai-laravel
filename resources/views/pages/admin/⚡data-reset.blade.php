<?php

use App\Actions\Backups\ResetApplicationData;
use App\Backups\BackupException;
use App\Backups\DataResetPlanner;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reset data')] class extends Component {
    public bool $keep_stores = false;

    public bool $keep_shift_types = false;

    public bool $keep_ui_prefs = false;

    public string $confirmation = '';

    public string $password = '';

    /** @var array{backup: string, tables: array<string, int>}|null */
    public ?array $result = null;

    public string $error = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);
    }

    public function confirmReset(): void
    {
        $this->resetErrorBag();
        $this->confirmation = '';
        $this->password = '';
        $this->error = '';

        Flux::modal('confirm-data-reset')->show();
    }

    public function executeReset(ResetApplicationData $reset): void
    {
        $this->resetErrorBag();
        $this->error = '';

        try {
            $this->result = $reset->handle(Auth::user(), $this->kept(), $this->confirmation, $this->password);
        } catch (BackupException $e) {
            $this->error = $e->getMessage();
            Flux::modal('confirm-data-reset')->close();

            return;
        }

        $this->confirmation = '';
        $this->password = '';
        Flux::modal('confirm-data-reset')->close();
        Flux::toast(variant: 'success', text: __('Data reset. A backup was made first.'));
    }

    /**
     * @return list<string>
     */
    private function kept(): array
    {
        return array_keys(array_filter([
            'stores' => $this->keep_stores,
            'shift_types' => $this->keep_shift_types,
            'ui_prefs' => $this->keep_ui_prefs,
        ]));
    }

    public function with(): array
    {
        abort_unless(Gate::allows('manage-settings'), 403);

        $planner = app(DataResetPlanner::class);

        return [
            'counts' => $planner->counts($planner->tablesToWipe($this->kept())),
            'phrase' => DataResetPlanner::CONFIRMATION,
        ];
    }
}; ?>

<section class="w-full space-y-8 p-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Reset data') }}</flux:heading>
            <flux:subheading>{{ __('Erase the planning data and start again, keeping the accounts, roles and settings.') }}</flux:subheading>
        </div>
        <flux:button :href="route('backups.index')" icon="archive-box" wire:navigate>{{ __('Backups') }}</flux:button>
    </div>

    @if ($error !== '')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="reset-error">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($result)
        <flux:callout variant="success" icon="check-circle" data-test="reset-done">
            <flux:callout.heading>{{ __('The data was reset.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('A backup was made just before: :backup', ['backup' => $result['backup']]) }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.text>{{ __('Accounts, roles and permissions, instance settings, API tokens and the activity log are never erased. The full factory reset, which goes back to the installation wizard, is not available yet.') }}</flux:callout.text>
    </flux:callout>

    <div class="max-w-2xl space-y-3" data-test="section-keep">
        <flux:heading size="lg">{{ __('What to keep') }}</flux:heading>
        <flux:checkbox wire:model.live="keep_stores" :label="__('Keep the stores and their members')" data-test="keep-stores" />
        <flux:checkbox wire:model.live="keep_shift_types" :label="__('Keep the shift types and hourly rates')" data-test="keep-shift-types" />
        <flux:checkbox wire:model.live="keep_ui_prefs" :label="__('Keep the interface preferences')" data-test="keep-ui-prefs" />
    </div>

    <div class="max-w-2xl space-y-3" data-test="section-plan">
        <flux:heading size="lg">{{ __('What will be erased') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Data') }}</flux:table.column>
                <flux:table.column>{{ __('Rows') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($counts as $table => $rows)
                    <flux:table.row :key="$table" data-test="plan-row">
                        <flux:table.cell>{{ \App\Backups\DataResetPlanner::label($table) }}</flux:table.cell>
                        <flux:table.cell>{{ $rows }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        <flux:text size="sm">{{ __('A backup is made automatically just before. If it fails, nothing is erased.') }}</flux:text>
        <flux:button variant="danger" wire:click="confirmReset" data-test="start-reset">{{ __('Reset the data…') }}</flux:button>
    </div>

    <flux:modal name="confirm-data-reset" class="max-w-lg">
        <form wire:submit="executeReset" class="space-y-6">
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Reset the data?') }}</flux:heading>
                <flux:text>{{ __('The data listed on the page is erased for everyone. You can get it back only by restoring the backup made just before.') }}</flux:text>
            </div>
            <flux:input wire:model="confirmation" :label="__('Type :text to confirm', ['text' => $phrase])" autocomplete="off" data-test="reset-confirmation" />
            @error('confirmation') <flux:text size="sm" class="text-red-600" data-test="reset-confirmation-error">{{ $message }}</flux:text> @enderror
            <flux:input wire:model="password" type="password" :label="__('Your password')" autocomplete="current-password" viewable data-test="reset-password" />
            @error('password') <flux:text size="sm" class="text-red-600" data-test="reset-password-error">{{ $message }}</flux:text> @enderror
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="confirm-reset">{{ __('Reset the data') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
