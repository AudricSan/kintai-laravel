<?php

use App\Actions\Availability\SaveAvailability;
use App\Models\Availability;
use App\Models\Store;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Availability')] class extends Component {
    /** @var array<int, array<int, array{available: bool, start: string, end: string}>> par magasin puis jour ISO */
    public array $days = [];

    public function mount(): void
    {
        $user = Auth::user();

        foreach ($user->stores()->get(['stores.id']) as $store) {
            $saved = Availability::query()->where('user_id', $user->id)->where('store_id', $store->id)->get()->keyBy('day_of_week');

            foreach (range(1, 7) as $day) {
                $row = $saved->get($day);
                // Sans ligne, l'employé n'a rien déclaré : on propose "disponible".
                $this->days[$store->id][$day] = [
                    'available' => $row === null ? true : $row->is_available,
                    'start' => $row?->starts_at ?? '',
                    'end' => $row?->ends_at ?? '',
                ];
            }
        }
    }

    public function save(int $storeId, SaveAvailability $saveAvailability): void
    {
        $this->resetErrorBag();

        $user = Auth::user();
        $store = $user->stores()->where('stores.id', $storeId)->firstOrFail();

        try {
            $saveAvailability->handle($user, $user, $store, $this->days[$storeId] ?? []);
        } catch (ValidationException $e) {
            // Clés propres à chaque magasin : plusieurs formulaires partagent la page.
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $errors["days.{$storeId}.{$key}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        Flux::toast(variant: 'success', text: __('Availability saved.'));
    }

    public function with(): array
    {
        return ['stores' => Auth::user()->stores()->orderBy('name')->get(['stores.id', 'stores.name'])];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Availability') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Availability')" :subheading="__('Tell your managers which days you cannot work. This is informational: it never blocks the schedule.')">
        @forelse ($stores as $store)
            <form wire:submit="save({{ $store->id }})" class="mb-10 space-y-4" wire:key="availability-{{ $store->id }}" data-test="availability-form-{{ $store->id }}">
                <flux:heading size="lg">{{ $store->name }}</flux:heading>

                @foreach (range(1, 7) as $day)
                    <div class="flex flex-wrap items-center gap-3" wire:key="availability-{{ $store->id }}-{{ $day }}">
                        <div class="w-32">
                            <flux:checkbox wire:model="days.{{ $store->id }}.{{ $day }}.available" :label="__(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day - 1])" />
                        </div>
                        <flux:input wire:model="days.{{ $store->id }}.{{ $day }}.start" type="time" :aria-label="__('Starts at')" class="w-32" />
                        <flux:input wire:model="days.{{ $store->id }}.{{ $day }}.end" type="time" :aria-label="__('Ends at')" class="w-32" />
                        @error('days.'.$store->id.'.'.$day.'.start') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                        @error('days.'.$store->id.'.'.$day.'.end') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                    </div>
                @endforeach

                <flux:button type="submit" variant="primary" data-test="save-availability-{{ $store->id }}">{{ __('Save') }}</flux:button>
            </form>
        @empty
            <flux:text>{{ __('You are not a member of any store.') }}</flux:text>
        @endforelse
    </x-pages::settings.layout>
</section>
