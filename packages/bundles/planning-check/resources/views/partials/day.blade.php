<flux:table data-test="day-shifts">
    <flux:table.columns>
        <flux:table.column>{{ __('Employee') }}</flux:table.column>
        <flux:table.column>{{ __('Type') }}</flux:table.column>
        <flux:table.column>{{ __('Hours') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Net duration') }}</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($day['rows'] as $row)
            <flux:table.row data-test="shift-row">
                <flux:table.cell>{{ $row['employee'] ?? __('Open shift (unassigned)') }}</flux:table.cell>
                <flux:table.cell>{{ $row['type'] }}</flux:table.cell>
                <flux:table.cell>{{ $row['start'] }}-{{ $row['end'] }}</flux:table.cell>
                <flux:table.cell align="end">{{ \Kintai\Bundles\PlanningCheck\DaySchedule::hours($row['net_min']) }}</flux:table.cell>
            </flux:table.row>
        @endforeach
        @if ($day['rows'] !== [])
            <flux:table.row>
                <flux:table.cell colspan="3"><strong>{{ __('Total') }}</strong></flux:table.cell>
                <flux:table.cell align="end"><strong data-test="day-total">{{ \Kintai\Bundles\PlanningCheck\DaySchedule::hours($day['total_minutes']) }}</strong></flux:table.cell>
            </flux:table.row>
        @endif
    </flux:table.rows>
</flux:table>
