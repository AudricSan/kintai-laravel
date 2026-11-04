<?php

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\RestoreShift;
use App\Actions\Shifts\UpdateShift;
use App\Authorization\Authorizer;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Planning\ShiftWageCalculator;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shift')] class extends Component {
    #[Locked]
    public ?int $shiftId = null;

    public string $store_id = '';

    /** Vide = shift ouvert (sans employé) */
    public string $user_id = '';

    public string $shift_date = '';

    public string $start_time = '09:00';

    public string $end_time = '17:00';

    public string $pause_minutes = '0';

    public string $notes = '';

    public string $hourly_rate_override = '';

    public string $net_minutes_override = '';

    /** @var list<string> avertissements en attente de confirmation */
    public array $warnings = [];

    public function mount(?Shift $shift = null): void
    {
        if ($shift === null || ! $shift->exists) {
            abort_unless(Gate::allows('shifts.create'), 403);

            $allowed = app(Authorizer::class)->storeIdsFor(Auth::user(), 'shifts.create');
            $this->store_id = (string) Store::query()
                ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
                ->orderBy('name')
                ->value('id');
            $this->shift_date = now()->toDateString();

            return;
        }

        abort_unless(Gate::allows('shifts.update', $shift), 403);

        $this->shiftId = $shift->id;
        $this->fillFromShift($shift);
    }

    private function shift(): ?Shift
    {
        return $this->shiftId === null ? null : Shift::withTrashed()->findOrFail($this->shiftId);
    }

    private function fillFromShift(Shift $shift): void
    {
        $this->store_id = (string) $shift->store_id;
        $this->user_id = (string) ($shift->user_id ?? '');
        $this->shift_date = $shift->shift_date->toDateString();
        $this->start_time = $shift->localStartsAt->format('H:i');
        $this->end_time = $shift->localEndsAt->format('H:i');
        $this->pause_minutes = (string) $shift->pause_minutes;
        $this->notes = (string) $shift->notes;
        $this->hourly_rate_override = $shift->hourly_rate_override === null ? '' : (string) $shift->hourly_rate_override;
        $this->net_minutes_override = $shift->net_minutes_override === null ? '' : (string) $shift->net_minutes_override;
    }

    /**
     * Le changement de magasin retire l'employé s'il n'en est pas membre.
     */
    public function updatedStoreId(): void
    {
        $isMember = $this->user_id !== '' && Store::query()->find((int) $this->store_id)
            ?->members()->wherePivot('is_active', true)->where('users.id', (int) $this->user_id)->exists();

        if (! $isMember) {
            $this->user_id = '';
        }
    }

    public function save(): void
    {
        $this->persist(false);
    }

    /**
     * Confirmation de la modale d'avertissements.
     */
    public function saveAnyway(): void
    {
        $this->persist(true);
    }

    private function persist(bool $force): void
    {
        $this->resetErrorBag();

        $actor = Auth::user();
        $shift = $this->shift();

        $input = [
            'store_id' => $this->store_id,
            'user_id' => $this->user_id,
            'shift_date' => $this->shift_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'pause_minutes' => $this->pause_minutes,
            'notes' => $this->notes,
        ];

        // Sans `payroll.generate`, les ajustements ne sont pas envoyés : ils restent tels quels.
        if ($this->canOverride()) {
            $input['hourly_rate_override'] = $this->hourly_rate_override;
            $input['net_minutes_override'] = $this->net_minutes_override;
        }

        try {
            $saved = $shift === null
                ? app(CreateShift::class)->handle($actor, $input, $force)
                : app(UpdateShift::class)->handle($actor, $shift, $input, $force);
        } catch (ShiftWarningsException $e) {
            $this->warnings = $e->messages();
            Flux::modal('shift-warnings')->show();

            return;
        }

        $this->warnings = [];
        Flux::modal('shift-warnings')->close();

        if ($shift === null) {
            $this->redirect(route('shifts.edit', $saved), navigate: true);

            return;
        }

        $this->fillFromShift($saved->refresh());
        Flux::toast(variant: 'success', text: __('Shift saved.'));
    }

    public function confirmDeletion(): void
    {
        $this->resetErrorBag();

        Flux::modal('confirm-shift-deletion')->show();
    }

    public function delete(): void
    {
        $this->resetErrorBag();

        app(DeleteShift::class)->handle(Auth::user(), Shift::query()->findOrFail($this->shiftId));

        Flux::modal('confirm-shift-deletion')->close();
        Flux::toast(variant: 'success', text: __('Shift deleted.'));
    }

    public function restore(): void
    {
        $this->resetErrorBag();

        app(RestoreShift::class)->handle(Auth::user(), Shift::onlyTrashed()->findOrFail($this->shiftId));

        Flux::toast(variant: 'success', text: __('Shift restored.'));
    }

    private function canOverride(): bool
    {
        return app(Authorizer::class)->allows(Auth::user(), 'payroll.generate', (int) $this->store_id ?: null);
    }

    /**
     * Aperçu du coût, réservé à `payroll.view` sur le magasin ; null si la saisie est incomplète.
     *
     * @return array{slices: list<array{name: string, minutes: int, rate: float, amount: float}>, total: float, net: int}|null
     */
    private function preview(): ?array
    {
        $store = Store::query()->find((int) $this->store_id);
        if ($store === null || ! app(Authorizer::class)->allows(Auth::user(), 'payroll.view', $store)) {
            return null;
        }

        $valid = Validator::make(
            ['s' => $this->start_time, 'e' => $this->end_time, 'p' => $this->pause_minutes, 'r' => $this->hourly_rate_override, 'n' => $this->net_minutes_override],
            ['s' => ['required', 'date_format:H:i'], 'e' => ['required', 'date_format:H:i'], 'p' => ['nullable', 'integer', 'min:0'], 'r' => ['nullable', 'numeric', 'min:0'], 'n' => ['nullable', 'integer', 'min:0']],
        )->passes();
        if (! $valid) {
            return null;
        }

        $user = $this->user_id === '' ? null : User::query()->find((int) $this->user_id);
        $result = app(ShiftWageCalculator::class)->estimate(
            $store,
            $user,
            $this->start_time,
            $this->end_time,
            (int) $this->pause_minutes,
            $this->hourly_rate_override === '' ? null : (float) $this->hourly_rate_override,
            $this->net_minutes_override === '' ? null : (int) $this->net_minutes_override,
        );

        return [
            'slices' => array_map(fn ($slice): array => [
                'name' => $slice->name,
                'minutes' => $slice->minutes,
                'rate' => $slice->rate,
                'amount' => $slice->amount,
            ], $result->slices),
            'total' => $result->total,
            'net' => $result->netMinutes,
        ];
    }

    public function with(): array
    {
        $authorizer = app(Authorizer::class);
        $actor = Auth::user();
        $shift = $this->shift();

        abort_unless($shift === null ? Gate::allows('shifts.create') : Gate::allows('shifts.update', $shift), 403);

        $allowed = $authorizer->storeIdsFor($actor, $shift === null ? 'shifts.create' : 'shifts.update');
        $store = Store::query()->find((int) $this->store_id);

        return [
            'isNew' => $shift === null,
            'isDeleted' => $shift?->trashed() ?? false,
            'isReplaced' => $shift?->replaced_by_id !== null,
            'canDelete' => $shift !== null && $authorizer->allows($actor, 'shifts.delete', $shift->store_id),
            'canOverride' => $this->canOverride(),
            'stores' => Store::query()
                ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
                ->orderBy('name')
                ->get(['id', 'name']),
            'employees' => $store === null ? collect() : $store->members()->wherePivot('is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']),
            'preview' => $this->preview(),
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-8 p-4">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ $isNew ? __('New shift') : __('Edit shift') }}</flux:heading>
        @unless ($isNew)
            <flux:text>#{{ $shiftId }}</flux:text>
        @endunless
    </div>

    @if ($isDeleted)
        <flux:callout variant="warning" icon="trash" data-test="deleted-banner">
            <flux:callout.heading>{{ __('This shift is deleted.') }}</flux:callout.heading>
            @unless ($isReplaced)
                @if ($canDelete)
                    <x-slot name="actions">
                        <flux:button wire:click="restore" data-test="restore-shift-button">{{ __('Restore') }}</flux:button>
                    </x-slot>
                @endif
            @else
                <flux:callout.text>{{ __('This shift was replaced by another shift and cannot be restored.') }}</flux:callout.text>
            @endunless
        </flux:callout>
        @error('shift') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="store_id" :label="__('Store')">
                @foreach ($stores as $store)
                    <flux:select.option value="{{ $store->id }}">{{ $store->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="user_id" :label="__('Employee')">
                <flux:select.option value="">{{ __('Open shift (no employee)') }}</flux:select.option>
                @foreach ($employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">{{ $employee->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="shift_date" :label="__('Date')" type="date" required />
            <flux:input wire:model.live="pause_minutes" :label="__('Break (minutes)')" type="number" min="0" max="1440" />
            <flux:input wire:model.live="start_time" :label="__('Starts at')" type="time" required />
            <flux:input wire:model.live="end_time" :label="__('Ends at')" :description="__('An end before the start means the shift runs past midnight.')" type="time" required />
        </div>
        @foreach (['store_id', 'user_id', 'shift_date', 'start_time', 'end_time', 'pause_minutes', 'shift'] as $field)
            @error($field) <flux:text class="text-red-500" data-test="error-{{ $field }}">{{ $message }}</flux:text> @enderror
        @endforeach

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" maxlength="2000" />
        @error('notes') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

        @if ($canOverride)
            <div class="space-y-2" data-test="overrides-section">
                <flux:heading size="lg">{{ __('Manual adjustment') }}</flux:heading>
                <flux:text>{{ __('Leave empty for the automatic calculation.') }}</flux:text>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live="hourly_rate_override" :label="__('Flat hourly rate')" type="number" step="0.01" min="0" />
                    <flux:input wire:model.live="net_minutes_override" :label="__('Paid minutes')" type="number" min="0" max="1500" />
                </div>
                @foreach (['hourly_rate_override', 'net_minutes_override'] as $field)
                    @error($field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                @endforeach
            </div>
        @endif

        @if ($preview !== null)
            <div class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="wage-preview">
                <flux:heading size="lg">{{ __('Estimated cost') }}</flux:heading>
                @forelse ($preview['slices'] as $slice)
                    <div class="flex justify-between text-sm">
                        <span>{{ $slice['name'] !== '' ? $slice['name'] : __('Flat rate') }} - {{ $slice['minutes'] }} min x {{ number_format($slice['rate'], 2) }}</span>
                        <span>{{ number_format($slice['amount'], 2) }}</span>
                    </div>
                @empty
                    <flux:text>{{ __('No shift type covers these hours.') }}</flux:text>
                @endforelse
                <div class="flex justify-between font-semibold">
                    <span>{{ __('Total') }} ({{ $preview['net'] }} min)</span>
                    <span data-test="wage-preview-total">{{ number_format($preview['total'], 2) }}</span>
                </div>
            </div>
        @endif

        <div class="flex justify-between gap-2">
            <div>
                @if (! $isNew && ! $isDeleted && $canDelete)
                    <flux:button type="button" wire:click="confirmDeletion" variant="danger" data-test="delete-shift-button">{{ __('Delete') }}</flux:button>
                @endif
            </div>
            @unless ($isDeleted)
                <flux:button type="submit" variant="primary" data-test="save-shift-button">{{ __('Save') }}</flux:button>
            @endunless
        </div>
    </form>

    <flux:modal name="shift-warnings" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Warnings') }}</flux:heading>
            <ul class="list-disc space-y-1 ps-5" data-test="shift-warnings">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button wire:click="saveAnyway" variant="primary" data-test="save-anyway-button">{{ __('Save anyway') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-shift-deletion" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this shift?') }}</flux:heading>
            <flux:text>{{ __('The shift goes to the trash and can be restored.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button wire:click="delete" variant="danger" data-test="confirm-shift-deletion-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
