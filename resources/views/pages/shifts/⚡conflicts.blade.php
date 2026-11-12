<?php

use App\Actions\Shifts\ResolveConflict;
use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\Store;
use App\Planning\ShiftConflictFinder;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shift conflicts')] class extends Component {
    /** Vide = tous les magasins visibles */
    public string $store_id = '';

    /** Mois "Y-m" */
    public string $month = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $this->month = now()->format('Y-m');
    }

    /**
     * "Garder le plus récent" : l'autre shift est remplacé par lui.
     */
    public function keepNewer(int $firstId, int $secondId): void
    {
        $this->resetErrorBag();

        $resolved = app(ResolveConflict::class)->handle(
            Auth::user(),
            Shift::query()->findOrFail($firstId),
            Shift::query()->findOrFail($secondId),
        );

        Flux::toast(variant: 'success', text: $resolved === null ? __('Nothing to resolve.') : __('Conflict resolved.'));
    }

    /**
     * Shifts actifs de la période, dans les magasins visibles de l'acteur.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Shift>
     */
    private function shifts(): \Illuminate\Database\Eloquent\Collection
    {
        $viewable = app(Authorizer::class)->storeIdsFor(Auth::user(), 'shifts.view');
        $month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? Carbon::parse($this->month.'-01') : now()->startOfMonth();

        return Shift::query()
            ->with(['store', 'user'])
            ->whereNull('replaced_by_id')
            ->when($viewable !== null, fn ($query) => $query->whereIn('store_id', $viewable))
            ->when($this->store_id !== '', fn ($query) => $query->where('store_id', (int) $this->store_id))
            ->between($month->copy()->startOfMonth(), $month->copy()->endOfMonth())
            ->orderBy('starts_at')
            ->get();
    }

    public function with(): array
    {
        abort_unless(Gate::allows('shifts.view'), 403);

        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $viewable = $authorizer->storeIdsFor($actor, 'shifts.view');
        $shifts = $this->shifts();
        $finder = app(ShiftConflictFinder::class);

        $pairs = collect($finder->overlappingPairs($shifts))
            ->sortByDesc(fn ($pair): int => $pair->first->starts_at->getTimestamp())
            ->values();

        return [
            'stores' => Store::query()
                ->when($viewable !== null, fn ($query) => $query->whereIn('id', $viewable))
                ->orderBy('name')
                ->get(['id', 'name']),
            'pairs' => $pairs,
            'tooShort' => $finder->tooShort($shifts),
            'tooLong' => $finder->tooLong($shifts),
            'canResolve' => fn ($pair): bool => $authorizer->allows($actor, 'shifts.update', $pair->first->store_id)
                && $authorizer->allows($actor, 'shifts.update', $pair->second->store_id),
        ];
    }
}; ?>

<section class="w-full max-w-5xl space-y-8 p-4">
    <flux:heading size="xl">{{ __('Shift conflicts') }}</flux:heading>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select wire:model.live="store_id" :label="__('Store')">
            <flux:select.option value="">{{ __('All stores') }}</flux:select.option>
            @foreach ($stores as $store)
                <flux:select.option value="{{ $store->id }}">{{ $store->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="month" :label="__('Month')" type="month" />
    </div>
    @error('shift') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

    <div class="space-y-3" data-test="overlaps-section">
        <flux:heading size="lg">{{ __('Overlapping shifts') }}</flux:heading>
        @forelse ($pairs as $pair)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="pair-{{ $pair->first->id }}-{{ $pair->second->id }}" data-test="overlap-row">
                <div class="space-y-1 text-sm">
                    <div class="font-semibold">{{ $pair->first->user?->name }} - {{ __(':minutes min of overlap', ['minutes' => $pair->overlapMinutes]) }}</div>
                    @foreach ([$pair->first, $pair->second] as $shift)
                        <div>
                            <a href="{{ route('shifts.edit', $shift) }}" wire:navigate class="underline">#{{ $shift->id }}</a>
                            {{ $shift->store->name }}: {{ $shift->localStartsAt->format('Y-m-d H:i') }} - {{ $shift->localEndsAt->format('H:i') }}
                        </div>
                    @endforeach
                </div>
                @if ($canResolve($pair))
                    <flux:button wire:click="keepNewer({{ $pair->first->id }}, {{ $pair->second->id }})" size="sm" data-test="keep-newer-button">{{ __('Keep the newest') }}</flux:button>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No overlapping shifts.') }}</flux:text>
        @endforelse
    </div>

    @foreach (['tooShort' => [__('Shifts that are too short'), $tooShort, 'too-short-section'], 'tooLong' => [__('Shifts that are too long'), $tooLong, 'too-long-section']] as $section)
        <div class="space-y-3" data-test="{{ $section[2] }}">
            <flux:heading size="lg">{{ $section[0] }}</flux:heading>
            @forelse ($section[1] as $shift)
                <div class="text-sm" wire:key="{{ $section[2] }}-{{ $shift->id }}">
                    <a href="{{ route('shifts.edit', $shift) }}" wire:navigate class="underline">#{{ $shift->id }}</a>
                    {{ $shift->user?->name ?? __('Open shift') }} - {{ $shift->store->name }}:
                    {{ $shift->localStartsAt->format('Y-m-d H:i') }} - {{ $shift->localEndsAt->format('H:i') }} ({{ $shift->durationMinutes }} min)
                </div>
            @empty
                <flux:text>{{ __('None.') }}</flux:text>
            @endforelse
        </div>
    @endforeach
</section>
