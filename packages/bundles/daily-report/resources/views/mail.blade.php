<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="UTF-8"><title>{{ __('Daily report') }}</title></head>
<body style="font-family:Arial,sans-serif;color:#222;max-width:600px;margin:auto;">
  <h2 style="color:#2c3e50;border-bottom:2px solid #2c3e50;padding-bottom:8px;">{{ __('Daily report') }} - {{ $store->name }}</h2>
  <p style="color:#666;">{{ __('Date') }} : <strong>{{ $report->report_date->toDateString() }}</strong></p>
  <table style="width:100%;border-collapse:collapse;margin-top:16px;">
    <tr style="background:#eaf2fb;"><td style="padding:8px 12px;font-weight:bold;">{{ __('Sales') }}</td><td style="padding:8px 12px;text-align:right;">{{ $sales }}</td></tr>
    <tr><td style="padding:8px 12px;font-weight:bold;">{{ __('Customers') }}</td><td style="padding:8px 12px;text-align:right;">{{ number_format($report->customer_count) }}</td></tr>
    <tr style="background:#eaf2fb;"><td style="padding:8px 12px;font-weight:bold;">{{ __('Labor') }}</td><td style="padding:8px 12px;text-align:right;">{{ $labor }}</td></tr>
    <tr><td style="padding:8px 12px;font-weight:bold;">{{ __('Waste') }}</td><td style="padding:8px 12px;text-align:right;">{{ $waste }}</td></tr>
    <tr style="background:#eaf2fb;"><td style="padding:8px 12px;font-weight:bold;">{{ __('Notes') }}</td><td style="padding:8px 12px;">{!! nl2br(e($report->notes ?? '—')) !!}</td></tr>
  </table>
  <p style="margin-top:24px;font-size:11px;color:#999;">Kintai - {{ $store->name }}</p>
</body>
</html>
