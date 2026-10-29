@extends('layout.app')
@section('content')
@php
use App\View\Components\Badge;
use App\View\Components\Button;
use App\View\Components\Flash;

/** @var array      $stores_map     id → nom */
/** @var int        $store_id */
/** @var array|null $store */
/** @var array|null $timesheet      TimesheetService::week() */
/** @var array|null $approval       validation de la semaine, ou null */
/** @var bool       $approval_stale shifts ou pointages modifiés depuis la validation */
/** @var bool       $can_approve    shifts.update sur le magasin */
/** @var array      $users_map      id → nom */
/** @var bool       $has_attendance un bundle actif fournit des pointages */

// Minutes en « 7 h 30 » ; signe explicite pour un écart.
$_hm = static function (int $minutes, bool $signed = false): string {
    $sign = $signed ? ($minutes > 0 ? '+' : ($minutes < 0 ? '−' : '')) : '';
    $m = abs($minutes);
    return $sign . intdiv($m, 60) . ' h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
$_time = static fn(?string $dt): string => $dt !== null && $dt !== '' ? substr($dt, 11, 5) : '—';
$_flagBadge = static function (string $flag): string {
    $badge = Badge::make(__('timesheet_flag_' . $flag))->sm();
    return (match ($flag) {
        'no_show', 'missing_clock_out' => $badge->danger(),
        'late', 'early_leave', 'unplanned' => $badge->warning(),
        'overtime', 'in_progress' => $badge->info(),
        'upcoming' => $badge->muted(),
        default => $badge->success(),
    })->render();
};

$_week  = $timesheet['from'] ?? date('Y-m-d');
$_prev  = date('Y-m-d', strtotime($_week . ' -7 days'));
$_next  = date('Y-m-d', strtotime($_week . ' +7 days'));
$_qs    = static fn(string $week): string => '?' . http_build_query(['store_id' => $store_id, 'week' => $week]);
$_flash = Flash::fromQuery('success', [
    'approved' => __('timesheet_approved_ok'),
    'reopened' => __('timesheet_reopened_ok'),
])->render();
$_anomalies = array_sum(array_column($timesheet['totals'] ?? [], 'anomalies'));
@endphp
<div class="page-header">
    <h2 class="page-header__title">🕒 {!! __('timesheets') !!}</h2>
    @if ($timesheet !== null)
    <div class="page-header__actions">
        {!! Button::make('⬇ ' . __('export_csv'))->ghost()->sm()->link(route_url('admin.timesheets.export') . $_qs($_week))->render() !!}
    </div>
    @endif
</div>

<div class="card card--filters mb-sm">
    <form method="GET" action="" class="filter-bar">
        <div class="shifts-filters__row">
            @if (count($stores_map) > 1)
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="ts-store">{!! __('store') !!}</label>
                <select id="ts-store" name="store_id" class="form-control form-control-sm" data-submit-on-change>
                    @foreach ($stores_map as $sid => $sname)
                        <option value="{!! (int) $sid !!}" {!! $store_id === (int) $sid ? 'selected' : '' !!}>{{ $sname }}</option>
                    @endforeach
                </select>
            </div>
            @else
            <input type="hidden" name="store_id" value="{!! (int) $store_id !!}">
            @endif
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="ts-week">{!! __('week') !!}</label>
                <input type="date" id="ts-week" name="week" value="{{ $_week }}" class="form-control form-control-sm" data-submit-on-change>
            </div>
            <div class="shifts-filters__actions">
                <a href="{!! route_url('admin.timesheets') . $_qs($_prev) !!}" class="btn btn--ghost btn--sm" aria-label="{{ __('prev_week') }}">←</a>
                <a href="{!! route_url('admin.timesheets') . $_qs(date('Y-m-d')) !!}" class="btn btn--ghost btn--sm">{!! __('today') !!}</a>
                <a href="{!! route_url('admin.timesheets') . $_qs($_next) !!}" class="btn btn--ghost btn--sm" aria-label="{{ __('next_week') }}">→</a>
            </div>
        </div>
    </form>
</div>

{!! $_flash !!}

@if ($timesheet === null)
    <div class="empty-state">{!! __('no_store_found') !!}</div>
@else
    @if (!$has_attendance)
        <div class="alert alert--warning">{!! __('timesheet_no_attendance') !!}</div>
    @endif

    <div class="timesheet-approval card mb-sm {!! $approval !== null ? ($approval_stale ? 'timesheet-approval--stale' : 'timesheet-approval--done') : '' !!}">
        <div class="timesheet-approval__text">
            <strong>{!! __('timesheet_week_of', ['from' => date_label($timesheet['from']), 'to' => date_label($timesheet['to'])]) !!}</strong>
            @if ($approval !== null)
                <span>✅ {!! __('timesheet_approved_by', ['name' => htmlspecialchars($users_map[(int) $approval['approved_by']] ?? '—'), 'date' => htmlspecialchars(substr($approval['approved_at'], 0, 16))]) !!}</span>
                @if ($approval_stale)
                    <span class="timesheet-approval__warn">⚠️ {!! __('timesheet_changed_since_approval') !!}</span>
                @endif
            @elseif ($_anomalies > 0)
                <span>{!! __('timesheet_anomalies_count', ['n' => $_anomalies]) !!}</span>
            @else
                <span>{!! __('timesheet_no_anomaly') !!}</span>
            @endif
        </div>
        @if ($can_approve)
        <div class="timesheet-approval__actions">
            @if ($approval === null || $approval_stale)
            <form method="POST" action="{!! route_url('admin.timesheets.approve') !!}" class="form-inline"
                  @if ($_anomalies > 0) data-confirm="{{ __('timesheet_approve_confirm', ['n' => $_anomalies]) }}" @endif>
                {!! csrf_field() !!}
                <input type="hidden" name="store_id" value="{!! (int) $store_id !!}">
                <input type="hidden" name="week" value="{{ $timesheet['from'] }}">
                {!! Button::make('✓ ' . __('timesheet_approve'))->primary()->sm()->submit()->render() !!}
            </form>
            @endif
            @if ($approval !== null)
            <form method="POST" action="{!! route_url('admin.timesheets.reopen') !!}" class="form-inline">
                {!! csrf_field() !!}
                <input type="hidden" name="store_id" value="{!! (int) $store_id !!}">
                <input type="hidden" name="week" value="{{ $timesheet['from'] }}">
                {!! Button::make(__('timesheet_reopen'))->ghost()->sm()->submit()->render() !!}
            </form>
            @endif
        </div>
        @endif
    </div>

    @if (empty($timesheet['rows']))
        <div class="empty-state">{!! __('timesheet_empty_week') !!}</div>
    @else
    <div class="card mb-sm">
        <h3 class="card__title">{!! __('timesheet_totals') !!}</h3>
        <div class="table-wrap">
            <table class="data-table" data-mob-stack>
                <thead><tr>
                    <th>{!! __('employee') !!}</th>
                    <th class="td-right">{!! __('timesheet_planned_minutes') !!}</th>
                    <th class="td-right">{!! __('timesheet_worked_minutes') !!}</th>
                    <th class="td-right">{!! __('timesheet_difference') !!}</th>
                    <th class="td-center">{!! __('timesheet_anomalies') !!}</th>
                </tr></thead>
                <tbody>
                @foreach ($timesheet['totals'] as $uid => $t)
                    <tr>
                        <td data-label="{{ __('employee') }}">{{ $users_map[$uid] ?? '#' . $uid }}</td>
                        <td class="td-right" data-label="{{ __('timesheet_planned_minutes') }}">{!! $_hm($t['planned_minutes']) !!}</td>
                        <td class="td-right" data-label="{{ __('timesheet_worked_minutes') }}">{!! $_hm($t['worked_minutes']) !!}</td>
                        <td class="td-right" data-label="{{ __('timesheet_difference') }}">{!! $_hm($t['worked_minutes'] - $t['planned_minutes'], true) !!}</td>
                        <td class="td-center" data-label="{{ __('timesheet_anomalies') }}">{!! $t['anomalies'] > 0 ? Badge::make((string) $t['anomalies'])->warning()->sm()->render() : '—' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 class="card__title">{!! __('timesheet_details') !!}</h3>
        <div class="table-wrap">
            <table class="data-table timesheet-table" data-mob-stack>
                <thead><tr>
                    <th>{!! __('employee') !!}</th>
                    <th>{!! __('date') !!}</th>
                    <th>{!! __('timesheet_planned') !!}</th>
                    <th>{!! __('timesheet_clocked') !!}</th>
                    <th class="td-right">{!! __('timesheet_worked_minutes') !!}</th>
                    <th>{!! __('timesheet_status') !!}</th>
                </tr></thead>
                <tbody>
                @foreach ($timesheet['rows'] as $r)
                    <tr class="{!! array_intersect($r['flags'], \App\Services\TimesheetService::ANOMALIES) !== [] ? 'timesheet-row--anomaly' : '' !!}">
                        <td data-label="{{ __('employee') }}">{{ $users_map[$r['user_id']] ?? '#' . $r['user_id'] }}</td>
                        <td data-label="{{ __('date') }}">{{ $r['date'] }}</td>
                        <td data-label="{{ __('timesheet_planned') }}">
                            @if ($r['shift'] !== null)
                                <a href="{!! $BASE_URL !!}/admin/shifts/{!! (int) $r['shift']['id'] !!}/edit">{{ substr((string) $r['shift']['start_time'], 0, 5) }} – {{ substr((string) $r['shift']['end_time'], 0, 5) }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td data-label="{{ __('timesheet_clocked') }}">
                            @if ($r['attendance'] !== null)
                                {!! $_time($r['attendance']['clock_in_time'] ?? null) !!} – {!! $_time($r['attendance']['clock_out_time'] ?? null) !!}
                            @else
                                —
                            @endif
                        </td>
                        <td class="td-right" data-label="{{ __('timesheet_worked_minutes') }}">{!! $r['attendance'] !== null ? $_hm($r['worked_minutes']) : '—' !!}</td>
                        <td data-label="{{ __('timesheet_status') }}">
                            @foreach ($r['flags'] as $_f)
                                {!! $_flagBadge($_f) !!}
                            @endforeach
                            @if ($r['late_minutes'] > 0)<span class="td-muted">{!! __('timesheet_minutes_late', ['n' => $r['late_minutes']]) !!}</span>@endif
                            @if ($r['early_minutes'] > 0)<span class="td-muted">{!! __('timesheet_minutes_early', ['n' => $r['early_minutes']]) !!}</span>@endif
                            @if ($r['overtime_minutes'] > 0)<span class="td-muted">{!! __('timesheet_minutes_over', ['n' => $r['overtime_minutes']]) !!}</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
@endif

@endsection
