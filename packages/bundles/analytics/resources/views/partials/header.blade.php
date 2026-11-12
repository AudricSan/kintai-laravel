{{-- En-tête commun : titre, sélecteur de magasin et de période. $title, $stores, $periods, $period (facultatif). --}}
<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <flux:heading size="xl">{{ $title }}</flux:heading>
        @isset($subtitle)
            <flux:subheading>{{ $subtitle }}</flux:subheading>
        @endisset
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if (count($stores) > 1)
            <flux:select wire:model.live="store" class="max-w-xs" data-test="store-select">
                @foreach ($stores as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>
</div>

@isset($period)
    <div class="flex flex-wrap gap-1" data-test="periods">
        @foreach ($periods as $days)
            <flux:button size="sm" :variant="$period === $days ? 'primary' : 'filled'" wire:click="setPeriod({{ $days }})" data-test="period-{{ $days }}">
                {{ match ($days) { 7 => __('7 days'), 30 => __('30 days'), 90 => __('3 months'), 180 => __('6 months'), default => __('1 year') } }}
            </flux:button>
        @endforeach
    </div>
@endisset
