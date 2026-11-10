{{-- Histogramme en CSS : $data (libellé => valeur), $color (classe Tailwind, facultatif). --}}
@php
    $values = array_map('floatval', $data);
    $max = $values === [] ? 0 : max($values);
@endphp
@if ($data === [])
    <flux:text size="sm">{{ __('No data') }}</flux:text>
@else
    <div class="flex h-36 items-stretch gap-1 overflow-x-auto" data-test="bars">
        @foreach ($values as $label => $value)
            <div class="flex h-full min-w-6 flex-1 flex-col items-center gap-1" title="{{ $label }}: {{ round($value, 1) }}">
                <span class="text-[10px] text-zinc-500">{{ round($value, 1) }}</span>
                <div class="flex w-full flex-1 items-end">
                    <div class="w-full rounded-t {{ $color ?? 'bg-indigo-500' }}" style="height: {{ \Kintai\Bundles\Analytics\Presenter::width($value, $max) }}%"></div>
                </div>
                <span class="max-w-full truncate text-[10px] text-zinc-500">{{ $label }}</span>
            </div>
        @endforeach
    </div>
@endif
