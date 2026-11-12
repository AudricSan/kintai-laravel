<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="UTF-8">
<title>{{ __('Salary report') }} - {{ $report->target_month }}</title>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #222; }
    h1 { font-size: 16pt; margin: 0; color: #2c3e50; }
    h2 { font-size: 11pt; margin: 14px 0 4px; color: #2c3e50; border-bottom: 1px solid #2c3e50; }
    h3 { font-size: 10pt; margin: 10px 0 2px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 4px 6px; text-align: left; }
    td.num, th.num, .text-right { text-align: right; }
    table.kpi td { border-bottom: 1px solid #ddd; }
    table.kpi td.value { text-align: right; font-weight: bold; }
    thead th { background: #e4e4e7; }
    .muted { color: #666; }
    .notes { border: 1px solid #ccc; padding: 6px; }
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
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        <a href="{{ route('salary-report.pdf.download', $report->id) }}">{{ __('Download the PDF') }}</a>
        <a href="{{ route('salary-report.show', $report->id) }}">{{ __('Back') }}</a>
    </div>
@endif

@php($money = fn ($amount) => \App\Support\Money::forStore($amount, $store))
@php($tz = config('kintai.timezone', 'UTC'))

<table>
    <tr>
        <td><h1>{{ $store->name }}</h1></td>
        <td class="num"><strong>{{ __('Salary report') }}</strong><br>{{ $report->target_month }}<br><span class="muted">{{ __('Issued on :date', ['date' => now($tz)->format('Y-m-d')]) }}</span></td>
    </tr>
</table>

<h2>{{ __('Basic information') }}</h2>
<table>
    <tr><td class="muted">{{ __('Report for') }}</td><td>{{ $report->employee?->name ?? __('Whole store') }}</td></tr>
    <tr><td class="muted">{{ __('Person in charge') }}</td><td>{{ $report->personInCharge?->name ?? '—' }}</td></tr>
</table>

<h2>{{ __('Summary') }}</h2>
<table class="kpi">
    <tr><td>{{ __('Total payments') }}</td><td class="value">{{ $money($report->total_payment) }}</td></tr>
    <tr><td>{{ __('Staff hours') }}</td><td class="value">{{ rtrim(rtrim($report->staff_man_hours, '0'), '.') ?: '0' }}</td></tr>
    <tr><td>{{ __('Staff gross pay') }}</td><td class="value">{{ $money($report->staff_total_payment) }}</td></tr>
    <tr><td>{{ __('Average hourly wage') }}</td><td class="value">{{ $money($report->staff_avg_hourly_wage) }}</td></tr>
    <tr><td>{{ __('Active employees') }}</td><td class="value">{{ number_format($report->active_employees) }}</td></tr>
</table>

<h2>{{ __('Deductions') }}</h2>
<table class="kpi">
    <tr><td>{{ __('Income tax base') }}</td><td class="value">{{ $money($report->income_tax_base) }}</td></tr>
    <tr><td>{{ __('Withholding tax') }}</td><td class="value">{{ $money($report->withholding_tax) }}</td></tr>
    <tr><td>{{ __('Residence tax') }}</td><td class="value">{{ $money($report->residence_tax) }}</td></tr>
    <tr><td>{{ __('Other deductions') }}</td><td class="value">{{ $money($report->other_deductions) }}</td></tr>
    @foreach ($report->deduction_lines ?? [] as $row)
        <tr><td>{{ $row['name'] }}@if ($row['kind'] === 'percent') ({{ $row['value'] }} %)@endif</td><td class="value">{{ $money($row['amount']) }}</td></tr>
    @endforeach
    <tr><td>{{ __('Total deductions') }}</td><td class="value">{{ $money($report->total_deductions) }}</td></tr>
</table>

<h2>{{ __('Payout') }}</h2>
<table class="kpi">
    <tr><td>{{ __('Net payment') }}</td><td class="value">{{ $money($report->net_payment) }}</td></tr>
    <tr><td>{{ __('Salary paid in cash') }}</td><td class="value">{{ $money($report->hand_delivered_salary) }}</td></tr>
    <tr><td>{{ __('Salary paid by bank transfer') }}</td><td class="value">{{ $money($report->bank_transfer_salary) }}</td></tr>
</table>

<h2>{{ __('Staff movements') }}</h2>
<table class="kpi">
    <tr><td>{{ __('New hires') }}</td><td class="value">{{ number_format($report->new_hires) }}</td></tr>
    <tr><td>{{ __('Departures') }}</td><td class="value">{{ number_format($report->resigned_staff) }}</td></tr>
</table>

@if (($report->employee_hours ?? []) !== [])
    <h2>{{ __('Employee hours') }}</h2>
    <table>
        @foreach ($report->employee_hours as $row)
            <tr><td>{{ $names[$row['user_id']] ?? '#'.$row['user_id'] }}</td><td class="num">{{ round($row['minutes'] / 60, 1) }} h</td></tr>
        @endforeach
    </table>
@endif

@if ($report->hire_registrations)
    <h2>{{ __('Hiring records') }}</h2>
    <div class="notes">{!! nl2br(e($report->hire_registrations)) !!}</div>
@endif

@if ($report->remarks)
    <h2>{{ __('Remarks') }}</h2>
    <div class="notes">{!! nl2br(e($report->remarks)) !!}</div>
@endif

@if ($detail !== null)
    <h2>{{ __('Calculation details') }}</h2>
    @include('salary-report::partials.detail', ['detail' => $detail, 'store' => $store, 'customLines' => $report->deduction_lines ?? []])
@endif

<table class="sign">
    <tr><td>{{ __('Prepared by') }}</td><td>{{ __('Approved by') }}</td></tr>
</table>
</body>
</html>
