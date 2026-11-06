<div class="space-y-2" data-test="day-shifts">
    <flux:heading size="lg">{{ __('Schedule for the next day') }}</flux:heading>
    @if ($rows === [])
        <flux:text size="sm" data-test="no-shifts">{{ __('No shift planned for this day.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                @if (in_array('employee', $columns, true))
                    <flux:table.column>{{ __('Employee') }}</flux:table.column>
                @endif
                @if (in_array('type', $columns, true))
                    <flux:table.column>{{ __('Shift type') }}</flux:table.column>
                @endif
                @if (in_array('schedule', $columns, true))
                    <flux:table.column>{{ __('Schedule') }}</flux:table.column>
                @endif
                @if (in_array('gross_hours', $columns, true))
                    <flux:table.column align="end">{{ __('Gross hours') }}</flux:table.column>
                @endif
                @if (in_array('pause', $columns, true))
                    <flux:table.column align="end">{{ __('Break') }}</flux:table.column>
                @endif
                @if (in_array('net_hours', $columns, true))
                    <flux:table.column align="end">{{ __('Net hours') }}</flux:table.column>
                @endif
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $row)
                    <flux:table.row data-test="shift-row">
                        @if (in_array('employee', $columns, true))
                            <flux:table.cell>{{ $row['employee'] }}</flux:table.cell>
                        @endif
                        @if (in_array('type', $columns, true))
                            <flux:table.cell>{{ $row['type'] }}</flux:table.cell>
                        @endif
                        @if (in_array('schedule', $columns, true))
                            <flux:table.cell>{{ $row['start'] }}-{{ $row['end'] }}</flux:table.cell>
                        @endif
                        @if (in_array('gross_hours', $columns, true))
                            <flux:table.cell align="end">{{ \Kintai\Bundles\DailyReport\DayShifts::hours($row['gross_min']) }}</flux:table.cell>
                        @endif
                        @if (in_array('pause', $columns, true))
                            <flux:table.cell align="end">{{ $row['pause_min'] > 0 ? $row['pause_min'].' min' : '—' }}</flux:table.cell>
                        @endif
                        @if (in_array('net_hours', $columns, true))
                            <flux:table.cell align="end">{{ \Kintai\Bundles\DailyReport\DayShifts::hours($row['net_min']) }}</flux:table.cell>
                        @endif
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
