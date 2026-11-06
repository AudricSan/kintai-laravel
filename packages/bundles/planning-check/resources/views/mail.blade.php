<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="UTF-8"><title>{{ __('Schedule of :date', ['date' => $date]) }}</title></head>
<body style="font-family:Arial,sans-serif;color:#222;max-width:600px;margin:auto;">
  <h2 style="color:#2c3e50;border-bottom:2px solid #2c3e50;padding-bottom:8px;">{{ __('Schedule of :date', ['date' => $date]) }}@if (! empty($context['updated'])) - {{ __('updated version') }}@endif</h2>
  <p>{{ __('Please find attached the schedule of :store for :date.', ['store' => $store->name, 'date' => $date]) }}</p>
  @if (! empty($context['validated_by']))
    <p style="color:#666;">{{ __('Validated by :name.', ['name' => $context['validated_by']]) }}</p>
  @endif
  <p style="margin-top:24px;font-size:11px;color:#999;">Kintai - {{ $store->name }}</p>
</body>
</html>
