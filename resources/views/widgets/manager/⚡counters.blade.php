<?php

use App\Dashboard\DashboardScope;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
    public function with(): array
    {
        $user = Auth::user();
        $scope = app(DashboardScope::class);
        $today = $scope->today()->toDateString();

        $employeeStoreIds = $scope->managedStoreIds($user, 'employees.view');
        $shiftStoreIds = $scope->managedStoreIds($user, 'shifts.view');

        $employees = DB::table('store_user')
            ->join('users', 'users.id', '=', 'store_user.user_id')
            ->where('store_user.is_active', true)
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereIn('store_user.store_id', $employeeStoreIds)
            ->distinct()
            ->count('store_user.user_id');

        $shifts = Shift::query()->whereIn('store_id', $shiftStoreIds)->whereNull('replaced_by_id');

        return [
            'counters' => [
                'employees' => ['label' => __('Active employees'), 'value' => $employees],
                'stores' => ['label' => __('Stores'), 'value' => count($scope->managedStoreIds($user, 'stores.view'))],
                'today' => ['label' => __('Shifts today'), 'value' => (clone $shifts)->where('shift_date', $today)->count()],
                'open' => ['label' => __('Upcoming open shifts'), 'value' => (clone $shifts)->open()->where('shift_date', '>=', $today)->count()],
            ],
        ];
    }
}; ?>

<x-dashboard.card :title="__('Key figures')" data-test="widget-counters">
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($counters as $key => $counter)
            <div wire:key="counter-{{ $key }}" data-test="counter-{{ $key }}">
                <div class="text-3xl font-semibold" data-test="counter-value">{{ $counter['value'] }}</div>
                <flux:text size="sm">{{ $counter['label'] }}</flux:text>
            </div>
        @endforeach
    </div>
</x-dashboard.card>
