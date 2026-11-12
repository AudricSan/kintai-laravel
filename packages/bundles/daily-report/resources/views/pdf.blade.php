<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="UTF-8">
<title>{{ __('Daily report') }} - {{ $report->report_date->toDateString() }}</title>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #222; }
    h1 { font-size: 16pt; margin: 0; color: #2c3e50; }
    h2 { font-size: 11pt; margin: 14px 0 4px; color: #2c3e50; border-bottom: 1px solid #2c3e50; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 4px 6px; text-align: left; }
    td.num, th.num { text-align: right; }
    table.kpi td { border-bottom: 1px solid #ddd; }
    table.kpi td.value { text-align: right; font-weight: bold; }
    table.shifts th { background: #e4e4e7; }
    table.shifts td { border-bottom: 1px solid #eee; }
    .muted { color: #666; }
    .notes { border: 1px solid #ccc; padding: 6px; }
    .status { font-weight: bold; }
    .sign td { width: 50%; padding-top: 30px; border-top: 1px solid #999; }
    @if ($preview)
    body { max-width: 780px; margin: 0 auto; padding: 16px; }
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
        <a href="{{ route('daily-report.pdf.download', $report->id) }}">{{ __('Download the PDF') }}</a>
        <a href="{{ route('daily-report.show', $report->id) }}">{{ __('Back') }}</a>
    </div>
@endif

@php($money = fn ($amount) => \App\Support\Money::forStore($amount, $store))
@php($tz = config('kintai.timezone', 'UTC'))

<table>
    <tr>
        <td><h1>{{ $store->name }}</h1></td>
        <td class="num"><strong>{{ __('Daily report') }}</strong><br>{{ $report->report_date->toDateString() }}<br><span class="muted">{{ __('Issued on :date', ['date' => now($tz)->format('Y-m-d')]) }}</span></td>
    </tr>
</table>

<h2>{{ __('Author') }}</h2>
<table>
    <tr><td class="muted">{{ __('Author') }}</td><td>{{ $author?->name ?? '—' }}</td></tr>
    @if ($author?->employee_code)
        <tr><td class="muted">{{ __('Employee code') }}</td><td>{{ $author->employee_code }}</td></tr>
    @endif
    <tr><td class="muted">{{ __('Created on') }}</td><td>{{ $report->created_at?->timezone($tz)->format('Y-m-d H:i') }}</td></tr>
</table>

<h2>{{ __('Daily report') }}</h2>
<table class="kpi">
    <tr><td>{{ __('Sales') }}</td><td class="value">{{ $money($report->sales_total) }}</td></tr>
    <tr><td>{{ __('Customers') }}</td><td class="value">{{ number_format($report->customer_count) }}</td></tr>
    <tr><td>{{ __('Labor') }}</td><td class="value">{{ $money($report->labor_cost) }}</td></tr>
    <tr><td>{{ __('Waste') }}</td><td class="value">{{ $money($report->waste_total) }}</td></tr>
</table>

<h2>{{ __('Schedule for the next day') }}</h2>
@if ($rows === [])
    <p class="muted">{{ __('No shift planned for this day.') }}</p>
@else
    <table class="shifts">
        <tr>
            @if (in_array('employee', $columns, true))<th>{{ __('Employee') }}</th>@endif
            @if (in_array('type', $columns, true))<th>{{ __('Shift type') }}</th>@endif
            @if (in_array('schedule', $columns, true))<th>{{ __('Schedule') }}</th>@endif
            @if (in_array('gross_hours', $columns, true))<th class="num">{{ __('Gross hours') }}</th>@endif
            @if (in_array('pause', $columns, true))<th class="num">{{ __('Break') }}</th>@endif
            @if (in_array('net_hours', $columns, true))<th class="num">{{ __('Net hours') }}</th>@endif
        </tr>
        @foreach ($rows as $row)
            <tr>
                @if (in_array('employee', $columns, true))<td>{{ $row['employee'] }}</td>@endif
                @if (in_array('type', $columns, true))<td>{{ $row['type'] }}</td>@endif
                @if (in_array('schedule', $columns, true))<td>{{ $row['start'] }}-{{ $row['end'] }}</td>@endif
                @if (in_array('gross_hours', $columns, true))<td class="num">{{ \Kintai\Bundles\DailyReport\DayShifts::hours($row['gross_min']) }}</td>@endif
                @if (in_array('pause', $columns, true))<td class="num">{{ $row['pause_min'] > 0 ? $row['pause_min'].' min' : '—' }}</td>@endif
                @if (in_array('net_hours', $columns, true))<td class="num">{{ \Kintai\Bundles\DailyReport\DayShifts::hours($row['net_min']) }}</td>@endif
            </tr>
        @endforeach
    </table>
@endif

@if ($report->notes)
    <h2>{{ __('Notes') }}</h2>
    <div class="notes">{!! nl2br(e($report->notes)) !!}</div>
@endif

<h2>{{ __('Status') }}</h2>
<table>
    <tr><td class="muted">{{ __('Status') }}</td><td class="status">{{ __(\Kintai\Bundles\DailyReport\Models\DailyReport::statusLabel($report->status)) }}</td></tr>
    @if ($report->submitted_at)
        <tr><td class="muted">{{ __('Submitted on') }}</td><td>{{ $report->submitted_at->timezone($tz)->format('Y-m-d H:i') }}</td></tr>
    @endif
    @if ($report->validated_at)
        <tr><td class="muted">{{ __('Validated on') }}</td><td>{{ $report->validated_at->timezone($tz)->format('Y-m-d H:i') }}</td></tr>
    @endif
</table>

<table class="sign">
    <tr>
        <td>{{ __('Author') }}<br><strong>{{ $author?->name ?? '—' }}</strong></td>
        <td>{{ __('Validated by') }}<br><strong>{{ $report->validator?->name ?? $store->name }}</strong></td>
    </tr>
</table>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
</body>
</html>
