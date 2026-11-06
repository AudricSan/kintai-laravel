{{-- Détail d'un calcul (voir SalaryCalculator::compute) : par employé et par type pour un magasin, jour par jour pour un employé. --}}
@php($hm = fn (int $minutes) => intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT))
@php($fmt = fn ($amount) => \App\Support\Money::forStore($amount, $store))

@if (($detail['mode'] ?? '') === 'store')
    <h3 class="mt-3 font-semibold">{{ __('Employees') }}</h3>
    <table class="w-full text-sm">
        <thead><tr><th class="text-left">{{ __('Employee') }}</th><th class="num text-right">{{ __('Hours') }}</th><th class="num text-right">{{ __('Gross pay') }}</th></tr></thead>
        <tbody>
            @forelse ($detail['employees'] as $row)
                <tr><td>{{ $row['name'] }}</td><td class="num text-right">{{ $row['hours'] }}</td><td class="num text-right">{{ $fmt($row['cost']) }}</td></tr>
            @empty
                <tr><td colspan="3">{{ __('No shift in this period.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($detail['type_breakdown'] !== [])
        <h3 class="mt-3 font-semibold">{{ __('Breakdown by shift type') }}</h3>
        <table class="w-full text-sm">
            <tbody>
                @foreach ($detail['type_breakdown'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="num text-right">{{ $hm($row['minutes']) }}</td><td class="num text-right">{{ $fmt($row['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if ($detail['deductions'] !== [])
        <h3 class="mt-3 font-semibold">{{ __('Deduction rates applied') }}</h3>
        <table class="w-full text-sm">
            <tbody>
                @foreach ($detail['deductions'] as $row)
                    <tr><td>{{ __($row['label']) }}</td><td class="num text-right">{{ $row['label'] === 'Resident tax' ? $fmt($row['value']) : $row['value'].' %' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
@elseif (($detail['mode'] ?? '') === 'employee')
    <h3 class="mt-3 font-semibold">{{ __('Shift details') }}</h3>
    <table class="w-full text-sm">
        <thead><tr><th class="text-left">{{ __('Day') }}</th><th class="text-left">{{ __('Shift type') }}</th><th class="text-left">{{ __('Schedule') }}</th><th class="num text-right">{{ __('Break') }}</th><th class="num text-right">{{ __('Net hours') }}</th><th class="num text-right">{{ __('Rate/h') }}</th><th class="num text-right">{{ __('Amount') }}</th></tr></thead>
        <tbody>
            @forelse ($detail['rows'] as $row)
                <tr>
                    <td>{{ $row['date'] }}</td><td>{{ $row['type'] }}</td><td>{{ $row['start'] }} - {{ $row['end'] }}</td>
                    <td class="num text-right">{{ $row['pause_min'] }} min</td><td class="num text-right">{{ $hm($row['net_min']) }}</td>
                    <td class="num text-right">{{ $row['has_rate'] ? $fmt($row['rate']) : '—' }}</td><td class="num text-right">{{ $fmt($row['cost']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">{{ __('No shift in this period.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($detail['type_breakdown'] !== [])
        <h3 class="mt-3 font-semibold">{{ __('Breakdown by shift type') }}</h3>
        <table class="w-full text-sm">
            <tbody>
                @foreach ($detail['type_breakdown'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="num text-right">{{ $hm($row['minutes']) }}</td><td class="num text-right">{{ $fmt($row['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <h3 class="mt-3 font-semibold">{{ __('Summary') }}</h3>
    <table class="w-full text-sm">
        <tbody>
            <tr><td>{{ __('Gross pay') }}</td><td class="num text-right">{{ $fmt($detail['gross_pay']) }}</td></tr>
            @foreach ($detail['deductions'] as $row)
                <tr><td>{{ __($row['label']) }}@if (! $row['flat']) ({{ $row['rate'] }} %)@endif</td><td class="num text-right">- {{ $fmt($row['amount']) }}</td></tr>
            @endforeach
            <tr><td><strong>{{ __('Net pay') }}</strong></td><td class="num text-right"><strong>{{ $fmt($detail['net_pay']) }}</strong></td></tr>
        </tbody>
    </table>
@endif
