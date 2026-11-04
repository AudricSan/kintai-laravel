<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ __('Timeline') }} - {{ $store->name }}</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: system-ui, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 16px; }
            h1 { font-size: 18px; margin: 0 0 4px; }
            .meta { color: #555; margin-bottom: 12px; }
            .day { border: 1px solid #999; margin-bottom: 14px; break-inside: avoid; }
            .day-header { background: #eee; padding: 4px 8px; display: flex; gap: 12px; align-items: center; }
            .day-header .tag { color: #b00020; font-weight: 600; }
            .grid { display: grid; grid-template-columns: 9rem 1fr 5rem; }
            .name, .total { padding: 2px 6px; border-top: 1px solid #ccc; display: flex; align-items: center; }
            .total { justify-content: flex-end; text-align: right; }
            .axis { display: flex; }
            .axis div { flex: 1; text-align: center; font-size: 9px; border-left: 1px solid #ccc; }
            .axis .under { background: #fecaca; }
            .axis .low { background: #fef3c7; }
            .track { position: relative; border-top: 1px solid #ccc; background-image: repeating-linear-gradient(to right, transparent 0, transparent calc(100% / 24 - 1px), #ddd calc(100% / 24 - 1px), #ddd calc(100% / 24)); }
            .bar { position: absolute; height: 22px; color: #fff; font-size: 10px; padding: 0 3px; line-height: 22px; overflow: hidden; white-space: nowrap; border-radius: 3px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .actions { margin-bottom: 12px; }
            @media print {
                .actions { display: none; }
                body { padding: 0; }
                @page { size: landscape; margin: 10mm; }
                .axis .under, .axis .low, .day-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div class="actions"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
        <h1>{{ $store->name }} - {{ __('Timeline') }}</h1>
        <div class="meta">
            {{ $viewport->firstDay->translatedFormat('d M Y') }}@if ($viewport->numDays > 1) - {{ $viewport->lastDay()->translatedFormat('d M Y') }}@endif
        </div>

        @foreach ($days as $day)
            <div class="day" data-test="print-day">
                <div class="day-header">
                    <strong>{{ $day['date']->translatedFormat('l d M') }}</strong>
                    <span>{{ trans_choice(':count shift|:count shifts', $day['shifts'], ['count' => $day['shifts']]) }} - {{ __(':count staff', ['count' => $day['staff']]) }}</span>
                    @if ($day['understaffed'])
                        <span class="tag">{{ __('Understaffed') }} ({{ $day['staff'] }}/{{ $store->min_staff_per_day }})</span>
                    @endif
                    <span style="margin-left:auto">{{ intdiv($day['net'], 60) }}h{{ str_pad((string) ($day['net'] % 60), 2, '0', STR_PAD_LEFT) }}@if ($withCost) - {{ \App\Support\Money::forStore($day['cost'] ?? 0, $store) }}@endif</span>
                </div>
                <div class="grid">
                    <div></div>
                    <div class="axis">
                        @foreach ($day['hourly'] as $hour => $count)
                            <div class="{{ $day['understaffedHours'][$hour] ? 'under' : ($day['lowHours'][$hour] ? 'low' : '') }}">{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}<br>{{ $count }}</div>
                        @endforeach
                    </div>
                    <div></div>
                    @foreach ($day['rows'] as $row)
                        @continue($row['bars'] === [] && $row['user'] === null)
                        <div class="name">{{ $row['user']?->name ?? __('Open shifts') }}</div>
                        <div class="track" style="height: {{ $row['lanes'] * 24 + 2 }}px">
                            @foreach ($row['bars'] as $bar)
                                <div class="bar" style="left: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%; top: {{ $bar['lane'] * 24 + 1 }}px; background-color: {{ $bar['color'] }}">{{ $bar['start'] }}-{{ $bar['end'] }}@if ($bar['cutEnd']) &rarr;@endif</div>
                            @endforeach
                        </div>
                        <div class="total">
                            @if ($row['net'] > 0)
                                {{ intdiv($row['net'], 60) }}h{{ str_pad((string) ($row['net'] % 60), 2, '0', STR_PAD_LEFT) }}@if ($withCost) {{ \App\Support\Money::forStore($row['cost'] ?? 0, $store) }}@endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </body>
</html>
