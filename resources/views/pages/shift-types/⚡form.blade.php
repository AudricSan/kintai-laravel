<?php

use App\Actions\ShiftTypes\SaveShiftType;
use App\Authorization\Authorizer;
use App\Models\ShiftType;
use App\Models\Store;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shift type')] class extends Component {
    #[Locked]
    public ?int $shiftTypeId = null;

    public string $code = '';

    public string $name = '';

    public string $starts_at = '08:00';

    public string $ends_at = '16:00';

    public string $hourly_rate = '0';

    public string $color = '#3B82F6';

    public string $sort_order = '0';

    public bool $is_active = true;

    /** @var list<string> identifiants des magasins cochés */
    public array $store_ids = [];

    public function mount(?ShiftType $shiftType = null): void
    {
        abort_unless(Gate::allows('shifts.update'), 403);

        if ($shiftType === null || ! $shiftType->exists) {
            return;
        }

        abort_unless(app(SaveShiftType::class)->canManageType(Auth::user(), $shiftType), 403);

        $this->shiftTypeId = $shiftType->id;
        $this->fillFromType($shiftType);
    }

    private function shiftType(): ?ShiftType
    {
        return $this->shiftTypeId === null ? null : ShiftType::query()->findOrFail($this->shiftTypeId);
    }

    private function fillFromType(ShiftType $type): void
    {
        $this->code = $type->code;
        $this->name = $type->name;
        $this->starts_at = $type->starts_at;
        $this->ends_at = $type->ends_at;
        $this->hourly_rate = number_format((float) $type->hourly_rate, 2, '.', '');
        $this->color = $type->color;
        $this->sort_order = (string) $type->sort_order;
        $this->is_active = $type->is_active;
        $this->store_ids = $type->stores()->pluck('stores.id')->map(fn ($id): string => (string) $id)->all();
    }

    public function save(SaveShiftType $saveShiftType): void
    {
        $isNew = $this->shiftTypeId === null;

        $type = $saveShiftType->handle(Auth::user(), $this->shiftType(), [
            'code' => $this->code,
            'name' => $this->name,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'hourly_rate' => $this->hourly_rate,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'store_ids' => $this->store_ids,
        ]);

        if ($isNew) {
            $this->redirect(route('shift-types.edit', $type), navigate: true);

            return;
        }

        $this->fillFromType($type->refresh());
        Flux::toast(variant: 'success', text: __('Shift type saved.'));
    }

    public function with(): array
    {
        abort_unless(Gate::allows('shifts.update'), 403);

        $allowed = app(Authorizer::class)->storeIdsFor(Auth::user(), 'shifts.update');

        return [
            'isNew' => $this->shiftTypeId === null,
            'stores' => Store::query()
                ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }
}; ?>

<section class="w-full max-w-3xl space-y-10 p-4">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ $isNew ? __('New shift type') : __('Edit shift type') }}</flux:heading>
        <flux:button :href="route('shift-types.index')" wire:navigate variant="filled">{{ __('Back to shift types') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="code" :label="__('Code')" :description="__('Letters, digits, dashes and underscores.')" type="text" required maxlength="20" />
            <flux:input wire:model="name" :label="__('Name')" type="text" required maxlength="100" />
            <flux:input wire:model="starts_at" :label="__('Starts at')" type="time" required />
            <flux:input wire:model="ends_at" :label="__('Ends at')" :description="__('An end before the start means the shift runs past midnight.')" type="time" required />
            <flux:input wire:model="hourly_rate" :label="__('Hourly rate')" type="number" step="0.01" min="0" />
            <flux:input wire:model="color" :label="__('Color')" type="color" />
            <flux:input wire:model="sort_order" :label="__('Sort order')" type="number" min="0" />
        </div>
        @foreach (['code', 'name', 'starts_at', 'ends_at', 'hourly_rate', 'color', 'sort_order'] as $field)
            @error($field) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        @endforeach

        <flux:checkbox wire:model="is_active" :label="__('Active shift type')" />

        <div class="space-y-2" data-test="stores-section">
            <flux:heading size="lg">{{ __('Stores') }}</flux:heading>
            @foreach ($stores as $store)
                <flux:checkbox wire:model="store_ids" value="{{ $store->id }}" :label="$store->name" wire:key="store-{{ $store->id }}" />
            @endforeach
            @error('store_ids') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
            @error('store_ids.*') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" data-test="save-shift-type-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
