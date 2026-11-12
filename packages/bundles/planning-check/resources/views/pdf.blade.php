<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="UTF-8">
<title>{{ __('Schedule of :date', ['date' => $date]) }}</title>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #222; }
    h1 { font-size: 16pt; margin: 0 0 4px; color: #2c3e50; }
    .meta { color: #666; margin-bottom: 10px; }
    .updated { color: #b45309; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eaf2fb; text-align: left; padding: 5px 8px; border-bottom: 2px solid #2c3e50; }
    td { padding: 5px 8px; border-bottom: 1px solid #ddd; }
    .r { text-align: right; }
    tfoot th { border-top: 2px solid #2c3e50; }
    .empty { padding: 20px; text-align: center; color: #666; font-size: 12pt; }
    .foot { margin-top: 14px; font-size: 8pt; color: #999; }
</style>
</head>
<body>
    <h1>{{ __('Schedule of :date', ['date' => $date]) }}</h1>
    <div class="meta">
        {{ $store->name }}
        @if (! empty($context['validated_by'])) - {{ __('validated by :name at :time', ['name' => $context['validated_by'], 'time' => $context['generated_at'] ?? '']) }}@endif
        @if (! empty($context['updated'])) - <span class="updated">{{ __('updated version at :time', ['time' => $context['generated_at'] ?? '']) }}</span>@endif
    </div>

    @if ($day['rows'] === [])
        <div class="empty">{{ __('No shift') }}</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ __('Employee') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Hours') }}</th>
                    <th class="r">{{ __('Net duration') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($day['rows'] as $row)
                <tr>
                    <td>{{ $row['open'] ? __('Open shift (unassigned)') : $row['employee'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ $row['start'] }} - {{ $row['end'] }}</td>
                    <td class="r">{{ \Kintai\Bundles\PlanningCheck\DaySchedule::hours($row['net_min']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">{{ __('Total') }}</th>
                    <th class="r">{{ \Kintai\Bundles\PlanningCheck\DaySchedule::hours($day['total_minutes']) }}</th>
                </tr>
            </tfoot>
        </table>
    @endif
    <div class="foot">Kintai - {{ $store->name }}</div>
</body>
</html>
