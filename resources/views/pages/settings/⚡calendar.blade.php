<?php

use App\Actions\Exports\ExportOwnSchedule;
use App\Actions\Ical\DeleteIcalToken;
use App\Actions\Ical\GenerateIcalToken;
use App\Models\IcalToken;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calendar')] class extends Component {
    /** Liens montrés une seule fois, juste après leur création : seule l'empreinte du jeton est stockée. @var array<int, string> */
    #[Locked]
    public array $revealed = [];

    /** Magasin visé par la modale de confirmation ouverte. */
    #[Locked]
    public int $pendingStoreId = 0;

    public string $exportStore = '';

    public string $exportFormat = 'csv';

    public string $exportFrom = '';

    public string $exportTo = '';

    public function mount(): void
    {
        $today = now((string) config('kintai.timezone'))->startOfDay();
        $this->exportFrom = $today->toDateString();
        $this->exportTo = $today->addDays(ExportOwnSchedule::DEFAULT_DAYS)->toDateString();
        $this->exportStore = (string) (Auth::user()->stores()->orderBy('stores.name')->value('stores.id') ?? '');
    }

    public function confirmRegenerate(int $storeId): void
    {
        $this->pendingStoreId = Auth::user()->stores()->where('stores.id', $storeId)->firstOrFail()->id;
        Flux::modal('confirm-ical-regenerate')->show();
    }

    public function confirmRemove(int $storeId): void
    {
        $this->pendingStoreId = Auth::user()->stores()->where('stores.id', $storeId)->firstOrFail()->id;
        Flux::modal('confirm-ical-delete')->show();
    }

    public function regeneratePending(GenerateIcalToken $generate): void
    {
        $this->generate($this->pendingStoreId, $generate);
        Flux::modal('confirm-ical-regenerate')->close();
    }

    public function removePending(DeleteIcalToken $delete): void
    {
        $this->remove($this->pendingStoreId, $delete);
        Flux::modal('confirm-ical-delete')->close();
    }

    public function generate(int $storeId, GenerateIcalToken $generate): void
    {
        $user = Auth::user();
        $store = $user->stores()->where('stores.id', $storeId)->firstOrFail();

        $token = $generate->handle($user, $user, $store);
        $this->revealed[$store->id] = route('ical.feed', ['token' => $token, 'store' => $store->id]);

        Flux::toast(variant: 'success', text: __('Calendar link generated. Copy it now: it will not be shown again.'));
    }

    public function remove(int $storeId, DeleteIcalToken $delete): void
    {
        $user = Auth::user();
        $store = $user->stores()->where('stores.id', $storeId)->firstOrFail();

        $delete->handle($user, $user, $store);
        unset($this->revealed[$store->id]);

        Flux::toast(variant: 'success', text: __('Calendar link deleted.'));
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'stores' => $user->stores()->orderBy('stores.name')->get(['stores.id', 'stores.name']),
            'linked' => IcalToken::query()->where('user_id', $user->id)->pluck('store_id')->all(),
            'formats' => ExportOwnSchedule::FORMATS,
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Calendar') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Calendar')" :subheading="__('Follow your shifts in your calendar app, or download your schedule.')">
        <div class="space-y-10">
            <div class="space-y-6" data-test="ical-links">
                <flux:heading size="lg">{{ __('Calendar links') }}</flux:heading>
                <flux:text size="sm">{{ __('Add a link to Google Calendar, Apple Calendar or Outlook to keep your shifts up to date. Anyone with the link can see your shifts: keep it private. Generating a new link stops the previous one from working.') }}</flux:text>

                @foreach ($stores as $store)
                    <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="ical-{{ $store->id }}" data-test="ical-store-{{ $store->id }}">
                        <flux:heading>{{ $store->name }}</flux:heading>

                        @if (isset($revealed[$store->id]))
                            <flux:input :value="$revealed[$store->id]" readonly copyable :label="__('Your calendar link')" data-test="ical-url-{{ $store->id }}" />
                            <flux:text size="sm">{{ __('Copy it now: it will not be shown again.') }}</flux:text>
                        @elseif (in_array($store->id, $linked, true))
                            <flux:text size="sm" data-test="ical-active-{{ $store->id }}">{{ __('A link is active. For security it cannot be shown again: generate a new one if you lost it.') }}</flux:text>
                        @else
                            <flux:text size="sm">{{ __('No link yet.') }}</flux:text>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            @if (in_array($store->id, $linked, true))
                                <flux:button size="sm" wire:click="confirmRegenerate({{ $store->id }})" data-test="ical-regenerate-{{ $store->id }}">{{ __('Generate a new link') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="confirmRemove({{ $store->id }})" data-test="ical-delete-{{ $store->id }}">{{ __('Delete the link') }}</flux:button>
                            @else
                                <flux:button size="sm" variant="primary" wire:click="generate({{ $store->id }})" data-test="ical-create-{{ $store->id }}">{{ __('Create a link') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <form method="GET" action="{{ route('schedule-export.download') }}" class="space-y-4" data-test="schedule-export">
                <flux:heading size="lg">{{ __('Export my schedule') }}</flux:heading>

                <flux:select name="store" wire:model="exportStore" :label="__('Store')">
                    @foreach ($stores as $store)
                        <flux:select.option value="{{ $store->id }}">{{ $store->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input name="from" wire:model="exportFrom" type="date" :label="__('From')" />
                    <flux:input name="to" wire:model="exportTo" type="date" :label="__('To')" />
                </div>
                <flux:select name="format" wire:model="exportFormat" :label="__('Format')">
                    @foreach ($formats as $format)
                        <flux:select.option value="{{ $format }}">{{ ['csv' => __('CSV'), 'json' => __('JSON'), 'ics' => __('Calendar (ICS)')][$format] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" icon="arrow-down-tray" data-test="schedule-export-submit">{{ __('Download') }}</flux:button>
            </form>
        </div>

        <flux:modal name="confirm-ical-regenerate" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Generate a new link?') }}</flux:heading>
                    <flux:subheading>{{ __('The previous link will stop working.') }}</flux:subheading>
                </div>
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="regeneratePending" data-test="confirm-ical-regenerate">{{ __('Generate a new link') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="confirm-ical-delete" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete this calendar link?') }}</flux:heading>
                    <flux:subheading>{{ __('Calendars using it will stop receiving your shifts.') }}</flux:subheading>
                </div>
                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="removePending" data-test="confirm-ical-delete">{{ __('Delete the link') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
