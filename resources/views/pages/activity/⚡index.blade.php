<?php

use App\Actions\Activity\PurgeActivityEntries;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use App\Activity\ActivityDetails;
use App\Activity\ActivityQuery;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity log')] class extends Component {
    use WithPagination;

    public const PER_PAGE = 100;

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public string $keepDays = '180';

    public function mount(): void
    {
        abort_unless(Gate::allows('stores.view'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ActivityQuery::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(ActivityQuery::FILTERS);
        $this->resetPage();
    }

    public function confirmPurge(): void
    {
        abort_unless(Gate::allows('manage-roles'), 403);

        Flux::modal('confirm-activity-purge')->show();
    }

    public function purge(PurgeActivityEntries $purge): void
    {
        $deleted = $purge->handle(Auth::user(), (int) $this->keepDays);

        $this->resetPage();
        Flux::modal('confirm-activity-purge')->close();
        Flux::toast(variant: 'success', text: trans_choice(':count entry deleted.|:count entries deleted.', $deleted, ['count' => $deleted]));
    }

    /**
     * @return array<string, string>
     */
    private function filters(): array
    {
        return ['action' => $this->action, 'actor' => $this->actor, 'store' => $this->store, 'from' => $this->from, 'to' => $this->to];
    }

    public function with(): array
    {
        abort_unless(Gate::allows('stores.view'), 403);

        $user = Auth::user();
        $activity = app(ActivityQuery::class);
        $actions = app(ActivityActionRegistry::class);

        $page = $activity->for($user, $this->filters())->with(['actor', 'store'])->paginate(self::PER_PAGE);

        return [
            'page' => $page,
            'actions' => $actions,
            'actionOptions' => $actions->grouped(),
            'actorOptions' => $activity->actorOptions($user),
            'storeOptions' => $activity->storeOptions($user),
            'exportParameters' => array_filter($this->filters(), fn (string $value): bool => $value !== ''),
            'canPurge' => Gate::allows('manage-roles'),
            'timezone' => (string) config('kintai.timezone'),
            'keepChoices' => PurgeActivityEntries::CHOICES,
        ];
    }
}; ?>

<section class="w-full space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Activity log') }}</flux:heading>
            <flux:subheading>{{ __('Who did what, and when.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="arrow-down-tray" :href="route('activity.export', $exportParameters)" data-test="export-activity">{{ __('Export CSV') }}</flux:button>
            @if ($canPurge)
                <flux:button icon="trash" variant="danger" wire:click="confirmPurge" data-test="purge-activity">{{ __('Purge') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <flux:select wire:model.live="action" :label="__('Action')" data-test="filter-action">
            <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
            @foreach ($actionOptions as $category => $items)
                <optgroup label="{{ $category }}">
                    @foreach ($items as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </optgroup>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="actor" :label="__('User')" data-test="filter-actor">
            <flux:select.option value="">{{ __('All users') }}</flux:select.option>
            @foreach ($actorOptions as $option)
                <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="store" :label="__('Store')" data-test="filter-store">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @foreach ($storeOptions as $option)
                <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="from" type="date" :label="__('From')" data-test="filter-from" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" data-test="filter-to" />
    </div>

    <flux:table :paginate="$page">
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('User') }}</flux:table.column>
            <flux:table.column>{{ __('Action') }}</flux:table.column>
            <flux:table.column>{{ __('Target') }}</flux:table.column>
            <flux:table.column>{{ __('Store') }}</flux:table.column>
            <flux:table.column>{{ __('Details') }}</flux:table.column>
            <flux:table.column>{{ __('IP address') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($page as $entry)
                <flux:table.row :key="$entry->id" data-test="activity-row">
                    <flux:table.cell class="whitespace-nowrap">{{ $entry->created_at?->setTimezone($timezone)->format('Y-m-d H:i:s') }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->actor?->name }}</flux:table.cell>
                    <flux:table.cell>{{ $actions->label($entry->action) }}</flux:table.cell>
                    <flux:table.cell>{{ ActivityDetails::target($entry) }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->store?->name }}</flux:table.cell>
                    <flux:table.cell>
                        @foreach (ActivityDetails::lines($entry) as $line)
                            <div class="text-sm"><span class="text-zinc-500">{{ $line['label'] }} :</span> {{ $line['value'] }}</div>
                        @endforeach
                    </flux:table.cell>
                    <flux:table.cell>{{ $entry->ip_address }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" data-test="no-activity">{{ __('No activity found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @if ($canPurge)
        <flux:modal name="confirm-activity-purge" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Purge the activity log') }}</flux:heading>
                    <flux:subheading>{{ __('Entries older than the period you keep are deleted. This cannot be undone.') }}</flux:subheading>
                </div>
                <flux:select wire:model="keepDays" :label="__('Keep the entries of the last')" data-test="keep-days">
                    @foreach ($keepChoices as $days)
                        <flux:select.option value="{{ $days }}">{{ $days === 0 ? __('Nothing (delete everything)') : trans_choice(':count day|:count days', $days, ['count' => $days]) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="purge" data-test="confirm-purge">{{ __('Purge') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
