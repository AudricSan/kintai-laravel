<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="UTF-8">
<title>{{ __('Salary reports') }}</title>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #222; }
    h1 { font-size: 15pt; margin: 0 0 8px; color: #2c3e50; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 4px 6px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #e4e4e7; }
    td.num, th.num { text-align: right; }
    .muted { color: #666; }
    @if ($preview)
    body { max-width: 900px; margin: 0 auto; padding: 16px; }
    .toolbar { margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #ccc; }
    .toolbar a, .toolbar button { margin-right: 8px; }
    @media print { .toolbar { display: none; } }
    @endif
</style>
</head>
<body>
@if ($preview)
    <div class="toolbar">
        <button type="button" data-print>{{ __('Print') }}</button>
        <a href="{{ route('salary-report.export.pdf.download', request()->query()) }}">{{ __('Download the PDF') }}</a>
        <a href="{{ route('salary-report.index') }}">{{ __('Back') }}</a>
    </div>
@endif

<h1>{{ __('Salary reports') }}</h1>
<p class="muted">{{ __('Issued on :date', ['date' => now(config('kintai.timezone', 'UTC'))->format('Y-m-d')]) }}</p>

<table>
    <thead>
        <tr>
            <th>{{ __('Month') }}</th>
            <th>{{ __('Store') }}</th>
            <th>{{ __('Employee') }}</th>
            <th class="num">{{ __('Hours') }}</th>
            <th class="num">{{ __('Gross pay') }}</th>
            <th class="num">{{ __('Deductions') }}</th>
            <th class="num">{{ __('Net pay') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($reports as $report)
            @php($money = fn ($amount) => \App\Support\Money::forStore($amount, $report->store))
            <tr>
                <td>{{ $report->target_month }}</td>
                <td>{{ $report->store->name }}</td>
                <td>{{ $report->employee?->name ?? __('Whole store') }}</td>
                <td class="num">{{ rtrim(rtrim($report->staff_man_hours, '0'), '.') ?: '0' }}</td>
                <td class="num">{{ $money($report->staff_total_payment) }}</td>
                <td class="num">{{ $money($report->total_deductions) }}</td>
                <td class="num">{{ $money($report->net_payment) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">{{ __('No salary reports match the filters.') }}</td></tr>
        @endforelse
    </tbody>
</table>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
</body>
</html>
