@php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Modal;

/** @var array  $entries       [{date, staff_name, start_time, end_time, hours, user_id,
 *                               duplicate_count, db_exact_match, db_overlap, db_overlap_shifts}] */
/** @var int    $store_id */
/** @var array  $all_users */
/** @var string $import_month  YYYY-MM si l'import couvre un seul mois, '' sinon */
/** @var array  $store         informations du store courant */

/* ── Statistiques d'import ─────────────────────── */
$matched         = count(array_filter($entries, fn($e) => ($e['user_id'] ?? 0) > 0));
$unmatched       = count($entries) - $matched;
$excelDuplicates = count(array_filter($entries, fn($e) => ($e['duplicate_count'] ?? 0) > 0));
$exactMatches    = count(array_filter($entries, fn($e) => !empty($e['db_exact_match'])));
$overlaps        = count(array_filter($entries, fn($e) => !empty($e['db_overlap'])));
$okCount         = count(array_filter($entries, fn($e) =>
    ((int)($e['user_id'] ?? 0)) > 0 &&
    empty($e['db_exact_match']) &&
    empty($e['db_overlap']) &&
    (($e['duplicate_count'] ?? 0) === 0)
));
@endphp

<div class="page-header">
    <h2 class="page-header__title">
        {!! __('preview') !!}        <span class="page-count">({!! count($entries) !!} {!! __('shifts') !!})</span>
    </h2>
    <a href="{!! route_url('admin.shifts.import') !!}" class="btn btn--ghost">← {!! __('start_over') !!}</a>
</div>

@php
if ($unmatched > 0)       echo Alert::make(__('unmatched_users_warning', ['n' => $unmatched]))->warning()->render();
if ($excelDuplicates > 0) echo Alert::make('⚠️ ' . __('excel_duplicates_warning', ['n' => $excelDuplicates]))->warning()->render();
if ($exactMatches > 0)    echo Alert::make('✅ ' . __('db_exact_match_warning', ['n' => $exactMatches]))->info()->render();
if ($overlaps > 0)        echo Alert::make('🔄 ' . __('import_overlap_auto', ['n' => $overlaps]))->info()->render();
@endphp

@php
/* Barre de filtres */
$filterTypes = [];
if ($okCount > 0)         $filterTypes[] = ['key' => 'ok',         'icon' => '✓',  'label' => __('import_filter_ok'),          'count' => $okCount];
if ($exactMatches > 0)    $filterTypes[] = ['key' => 'exact',      'icon' => '✅', 'label' => __('import_status_identical'),    'count' => $exactMatches];
if ($overlaps > 0)        $filterTypes[] = ['key' => 'overlap',    'icon' => '🔄', 'label' => __('import_status_overlap'),      'count' => $overlaps];
if ($excelDuplicates > 0) $filterTypes[] = ['key' => 'duplicate',  'icon' => '⚠️', 'label' => __('duplicate_excel'),            'count' => $excelDuplicates];
if ($unmatched > 0)       $filterTypes[] = ['key' => 'unassigned', 'icon' => '👤', 'label' => __('not_assigned'),               'count' => $unmatched];
@endphp
@if (count($filterTypes) > 1)
<div class="import-filter-bar" id="import-filter-bar">
    <span class="import-filter-label">{!! __('import_filter_label') !!}</span>
    @foreach ($filterTypes as $ft)
    <button type="button"
            class="import-filter-btn active"
            data-filter="{!! $ft['key'] !!}">
        {!! $ft['icon'] !!} {{ $ft['label'] }}        <span class="import-filter-badge">{!! $ft['count'] !!}</span>
    </button>
    @endforeach
    <span id="import-filter-count" class="import-filter-count">
        {!! __('import_filter_count', ['n' => count($entries)]) !!}    </span>
</div>
@endif

<!-- ── Tableau des shifts à importer ─────────────── -->
<form id="import-form" method="POST" action="{!! route_url('admin.shifts.import.confirm') !!}">
    {!! csrf_field() !!}    <input type="hidden" name="store_id"    value="{!! (int) $store_id !!}">
    <input type="hidden" name="shifts_json" id="shifts_json" value="">

    <div class="card">
        <div class="table-wrap">
            <table class="data-table" data-mob-stack>
                <thead>
                    <tr>
                        <th>{!! __('date') !!}</th>
                        <th>{!! __('excel_name') !!}</th>
                        <th>{!! __('start') !!}</th>
                        <th>{!! __('end') !!}</th>
                        <th>{!! __('duration') !!}</th>
                        <th>{!! __('user') !!}</th>
                        <th>{!! __('status') !!}</th>
                        <th class="td-center">{!! __('ignore') !!}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $i => $e)
                    @php
                        $noUser       = ((int) ($e['user_id'] ?? 0)) <= 0;
                        $isDupExcel   = ($e['duplicate_count'] ?? 0) > 0;
                        $isExactMatch = !empty($e['db_exact_match']);
                        $isOverlap    = !empty($e['db_overlap']);

                        $preSkip = $isDupExcel || $isExactMatch;

                        $rowClass = '';
                        if ($isExactMatch) $rowClass = 'tr-muted';
                        elseif ($isOverlap) $rowClass = 'tr-warn-dup';
                        elseif ($isDupExcel) $rowClass = 'tr-warn-dup';
                        elseif ($noUser)    $rowClass = 'tr-warn';

                        if ($isExactMatch)  $conflictType = 'exact';
                        elseif ($isOverlap) $conflictType = 'overlap';
                        elseif ($isDupExcel) $conflictType = 'duplicate';
                        elseif ($noUser)    $conflictType = 'unassigned';
                        else                $conflictType = 'ok';
                    @endphp
                    <tr data-idx="{!! $i !!}"
                        data-date="{{ $e['date'] }}"
                        data-staff="{{ $e['staff_name'] }}"
                        data-conflict="{!! $conflictType !!}"
                        class="{!! $rowClass !!}">
                        <td data-label="{{ __('date') }}" class="td-nowrap">{{ $e['date'] }}</td>
                        <td data-label="{{ __('excel_name') }}">{{ $e['staff_name'] }}</td>
                        <td data-label="{{ __('start') }}">
                            <input type="time"
                                   data-field="start_time"
                                   value="{{ $e['start_time'] ?? '00:00' }}"
                                   class="form-control import-time-input">
                        </td>
                        <td data-label="{{ __('end') }}">
                            <input type="time"
                                   data-field="end_time"
                                   value="{{ $e['end_time'] ?? '00:00' }}"
                                   class="form-control import-time-input">
                        </td>
                        <td data-label="{{ __('duration') }}" class="td-nowrap td-muted td-sm">
                            {!! number_format((float) ($e['hours'] ?? 0), 1) !!} h
                        </td>
                        <td data-label="{{ __('user') }}" class="td-user-cell">
                            <select data-field="user_id"
                                    class="form-control import-select">
                                <option value="0">— {!! __('not_assigned') !!} —</option>
                                @foreach ($all_users as $u)
                                    @php
                                    $uid  = (int) $u['id'];
                                    $name = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
                                    if ($name === '') $name = $u['display_name'] ?? ('User #' . $uid);
                                    @endphp
                                    <option value="{!! $uid !!}"
                                        {!! ((int) ($e['user_id'] ?? 0)) === $uid ? 'selected' : '' !!}>
                                        {{ $name }}                                    </option>
                                @endforeach
                            </select>
                            @php if ($noUser):
                                echo Button::make(__('quick_create_staff'))
                                    ->ghost()->xs()
                                    ->attrs([
                                        'class' => 'qc-open-btn',
                                        'data-staff' => $e['staff_name'],
                                        'title' => __('quick_create_title'),
                                    ])->render();
                            endif; @endphp
                        </td>
                        <td data-label="{{ __('status') }}" class="td-nowrap td-sm">
                            @php if ($isExactMatch):
                                echo Badge::make('✅ ' . __('import_status_identical'))->active()->render();
                            elseif ($isOverlap):
                                echo Badge::make('🔄 ' . __('import_status_overlap'))->warning()->render();
                                foreach ($e['db_overlap_shifts'] as $ox): @endphp
                                <div class="td-sm td-muted mt-2px">{{ $ox['start_time'] }}–{{ $ox['end_time'] }}</div>
                                @php endforeach;
                            elseif ($isDupExcel):
                                echo Badge::make('⚠️ ' . __('duplicate_excel'))->warning()->render();
                            else:
                                echo Badge::make(__('ok'))->active()->render();
                            endif; @endphp
                        </td>
                        <td data-label="{{ __('ignore') }}" class="td-center">
                            <input type="checkbox" data-field="skip" value="1"
                                   class="import-checkbox"
                                   {!! $preSkip ? 'checked' : '' !!}>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Actions ──────────────────────────────── -->
    <div class="import-preview-actions">
        {!! Button::make(__('confirm_import', ['count' => $matched]))->primary()->submit()->render() !!}        <a href="{!! route_url('admin.shifts.import') !!}" class="btn btn--ghost">{!! __('cancel') !!}</a>
        @if ($unmatched > 0)
            <span class="text-sm-muted">{!! __('unassigned_ignored_hint') !!}</span>
        @endif
    </div>
</form>

@php
ob_start();
@endphp<p id="qc-intro" class="text-sm"></p>
<div id="qc-alert" class="alert mb-sm hidden"></div>
<form id="qc-form" method="POST" action="{!! route_url('admin.users.quick_create') !!}">
    {!! csrf_field() !!}    <input type="hidden" name="store_id" value="{!! (int) $store_id !!}">
    @php
    $mode = 'create';
    $user = [];
    $all_stores = [];
    @endphp
    @includeInScope('_partials._form-user')
    <div class="form-actions">
        {!! Button::make(__('create'))->primary()->submit()->attrs(['id' => 'qc-submit'])->render() !!}        {!! Button::make(__('cancel'))->ghost()->attrs(['data-on-click' => 'qcClose'])->render() !!}    </div>
</form>
@php
echo Modal::make(__('quick_create_title'), ob_get_clean())
    ->attrs(['id' => 'qc-overlay'])
    ->wide()
    ->render();
@endphp

<script type="application/json" id="kintai-import-data">{!! json_encode([
    'baseUrl'   => $BASE_URL,
    'storeId'   => (int) $store_id,
    'filterTpl' => __('import_filter_count'),
    'i18n'      => [
        'introTpl'       => __('quick_create_intro'),
        'msgSuccess'     => __('quick_create_success'),
        'msgError'       => __('quick_create_error'),
        'propagatedRow'  => __('propagated_row'),
        'propagatedRows' => __('propagated_rows'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script src="{!! $BASE_URL !!}/assets/js/modules/user-form-live-check.js"></script>
<script src="{!! $BASE_URL !!}/assets/js/modules/furigana-suggest.js"></script>
<script src="{!! $BASE_URL !!}/assets/js/modules/shifts-import-preview.js"></script>
