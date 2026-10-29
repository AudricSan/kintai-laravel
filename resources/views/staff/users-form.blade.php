@extends('layout.app')
@section('content')
@php
use App\View\Components\Badge;
use App\View\Components\Button;
use App\View\Components\Card;
use App\View\Components\Flash;
use App\View\Components\Modal;

/**
 * @var string $mode
 * @var array  $user
 * @var array  $all_stores
 * @var array  $user_memberships
 * @var array  $available_stores
 * @var array  $user_shift_types
 * @var array  $user_rates
 * @var array  $stores_map
 */
$user_shift_types      ??= [];
$user_rates            ??= [];
$stores_map            ??= [];
$type_store_names      ??= [];
$user_memberships      ??= [];
$available_stores      ??= [];
$all_stores            ??= [];
$assignable_roles      ??= [];
$default_store_role_id ??= 0;
$action = $mode === 'edit'
    ? $BASE_URL . '/admin/users/' . employee_segment((int) $user['id']) . '/edit'
    : route_url('admin.users.create');
$can = $user_can ?? fn(string $k): bool => true;
// admin.users.edit n'exige que employees.view, mais la soumission réelle
// (admin.users.update) exige employees.update : sans ce droit, le formulaire
// reste consultable mais désactivé plutôt que de laisser l'utilisateur le
// remplir pour se faire rejeter au clic sur "Enregistrer" (ou pire, perdre
// silencieusement une modification via l'autosave, voir user-form-autosave.js).
$canEditUser = $mode !== 'edit' || $can('employees.update');

echo Flash::fromQuery('success', [
    'updated'        => __('user_updated'),
    'rate_updated'   => __('user_rate_updated'),
    'rate_deleted'   => __('user_rate_deleted'),
    'password_reset' => __('password_reset_success'),
])->render();
echo Flash::fromQuery('error', [
    'email_taken'          => __('email_taken'),
    'name_required'        => __('name_required'),
    'furigana_required'    => __('furigana_required'),
    'plan_limit_employees' => __('plan_limit_employees'),
    'employee_code_invalid_url' => __('employee_code_invalid_url'),
    'employee_code_taken'  => __('val_employee_code_taken'),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! $mode === 'edit' ? __('edit_user') : __('new_user') !!}</h2>
    <div class="page-header__actions">
        <a href="{!! back_url(route_url('admin.users')) !!}" class="btn btn--ghost">← {!! __('back') !!}</a>
    </div>
</div>

@if (!$canEditUser)
<div class="alert alert--warning">{!! __('readonly_no_permission_notice') !!}</div>
@endif

<form method="POST" action="{{ $action }}" id="userEditForm" class="form-stack" novalidate{!! ($mode === 'edit' && $canEditUser) ? ' data-autosave="1"' : '' !!}>
    <fieldset{!! $canEditUser ? '' : ' disabled' !!} class="fieldset-reset">
        {!! csrf_field() !!}        @php $as_cards = true; echo $__env->make('_partials._form-user', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); @endphp
    </fieldset>
</form>
@if ($mode === 'edit')
<form method="POST" action="{!! $BASE_URL !!}/admin/users/{!! employee_segment((int) $user['id']) !!}/reset-password" id="resetPasswordForm">
    {!! csrf_field() !!}</form>
@endif
<script src="{!! $BASE_URL !!}/assets/js/modules/required-fields-feedback.js"></script>
@if ($mode === 'edit')
<script src="{!! $BASE_URL !!}/assets/js/modules/user-form-autosave.js"></script>
@endif

@if ($mode === 'edit')
<!-- Stores + Cotisations sociales + Taux horaires personnalisés : regroupés dans une
     seule box (demande explicite), séparés par des sous-en-têtes card-header--border-top.
     "Rôle et apparence" (couleur, statut, compte Owner) a été redistribué dans la carte
     "Informations employé" de la grille du haut, voir _form-user.php. -->
<div class="card card--mt">
    <div class="card-header card-header--flex">
        <h3 class="card-title">{!! __('stores_plural') !!}</h3>
        @if (!empty($available_stores) && $can('employees.update'))
        {!! Button::make(__('assign_to_store'))->outline()->sm()->attrs(['type' => 'button', 'data-on-click' => 'openModal', 'data-args' => '["assignStoreModal"]'])->render() !!}        @endif
    </div>
    <div class="table-wrap">
        <table class="data-table" data-mob-stack>
            <thead><tr>
                <th>{!! __('store') !!}</th>
                <th>{!! __('role') !!}</th>
                <th>{!! __('social_deductions') !!}</th>
                <th>{!! __('shift_types') !!}</th>
                <th>{!! __('base_rate') !!}</th>
                <th>{!! __('custom_rate') !!}</th>
            </tr></thead>
            <tbody>
                @if (empty($user_memberships))
                    <tr><td colspan="6" class="td-center td-muted">{!! __('no_store_assigned') !!}</td></tr>
                @else
                    @php foreach ($user_memberships as $m):
                        $sid = (int) $m['store_id'];
                        $mid = (int) $m['id'];
                        $ov = $m['ded_overrides'] ?? [];
                        $storeDedEnabled = !empty($m['store_ded_settings']['enabled']);
                        $subject = !empty($ov['subject_to_deductions']);
                        $storeTypes = $shift_types_by_store[$sid] ?? [];
                        $rowSpan = max(1, count($storeTypes));
                    @endphp
                        @if (empty($storeTypes))
                        <tr class="tr--clickable" data-modal="editMembershipModal{!! $mid !!}">
                            <td data-label="{{ __('store') }}">{{ $m['store_name'] ?? '' }}</td>
                            <td data-label="{{ __('role') }}">{!! Badge::make($m['role_name'] ?? '—')->variant(!empty($m['role_is_managing']) ? 'warning' : 'active')->render() !!}</td>
                            <td data-label="{{ __('social_deductions') }}">
                                @if ($storeDedEnabled && $can('payroll.generate'))
                                <form method="POST" action="{!! $BASE_URL !!}/admin/stores/{!! store_segment($sid) !!}/members/{!! $mid !!}/deductions" class="form-inline">
                                    {!! csrf_field() !!}                                    <input type="hidden" name="_redirect_to" value="{{ $BASE_URL . '/admin/users/' . employee_segment((int) $user['id']) . '/edit?success=deductions_saved' }}">
                                    <label class="form-toggle" title="{{ __('subject_to_deductions') }}">
                                        <input type="hidden" name="subject_to_deductions" value="0">
                                        <input type="checkbox" name="subject_to_deductions" value="1" class="form-toggle__input" {!! $subject ? 'checked' : '' !!} data-submit-on-change>
                                        <span class="form-toggle__track"></span>
                                    </label>
                                </form>
                                @else
                                    {!! Badge::make(__('deductions_disabled'))->variant('muted')->render() !!}                                @endif
                            </td>
                            <td colspan="3" class="text-sm text-dim">—</td>
                        </tr>
                        @else
                        @php foreach ($storeTypes as $i => $t):
                            $tid = (int) $t['id'];
                            $currentRate = $user_rates[$tid] ?? null;
                        @endphp
                        <tr class="tr--clickable" data-modal="editMembershipModal{!! $mid !!}">
                            @if ($i === 0)
                            <td rowspan="{!! $rowSpan !!}" data-label="{{ __('store') }}">{{ $m['store_name'] ?? '' }}</td>
                            <td rowspan="{!! $rowSpan !!}" data-label="{{ __('role') }}">{!! Badge::make($m['role_name'] ?? '—')->variant(!empty($m['role_is_managing']) ? 'warning' : 'active')->render() !!}</td>
                            <td rowspan="{!! $rowSpan !!}" data-label="{{ __('social_deductions') }}">
                                @if ($storeDedEnabled && $can('payroll.generate'))
                                <form method="POST" action="{!! $BASE_URL !!}/admin/stores/{!! store_segment($sid) !!}/members/{!! $mid !!}/deductions" class="form-inline">
                                    {!! csrf_field() !!}                                    <input type="hidden" name="_redirect_to" value="{{ $BASE_URL . '/admin/users/' . employee_segment((int) $user['id']) . '/edit?success=deductions_saved' }}">
                                    <label class="form-toggle" title="{{ __('subject_to_deductions') }}">
                                        <input type="hidden" name="subject_to_deductions" value="0">
                                        <input type="checkbox" name="subject_to_deductions" value="1" class="form-toggle__input" {!! $subject ? 'checked' : '' !!} data-submit-on-change>
                                        <span class="form-toggle__track"></span>
                                    </label>
                                </form>
                                @else
                                    {!! Badge::make(__('deductions_disabled'))->variant('muted')->render() !!}                                @endif
                            </td>
                            @endif
                            <td data-label="{{ __('shift_types') }}">{{ $t['name'] ?? '' }} <span class="text-sm-muted">({{ $t['code'] ?? '' }})</span></td>
                            <td data-label="{{ __('base_rate') }}" class="text-sm">{!! $t['hourly_rate'] !== null ? number_format((float) $t['hourly_rate'], 2, '.', '') : '—' !!}</td>
                            <td data-label="{{ __('custom_rate') }}">
                                @if ($can('employees.update'))
                                <form method="POST" action="{!! $BASE_URL !!}/admin/users/{!! employee_segment((int) $user['id']) !!}/rates" class="form-inline">
                                    {!! csrf_field() !!}                                    <input type="hidden" name="shift_type_id" value="{!! $tid !!}">
                                    <input type="number" name="hourly_rate" class="form-control form-control-sm w-90" min="0" step="0.01"
                                           value="{!! $currentRate !== null ? number_format((float) $currentRate['hourly_rate'], 2, '.', '') : '' !!}"
                                           placeholder="{{ $t['hourly_rate'] !== null ? number_format((float) $t['hourly_rate'], 2, '.', '') : '0.00' }}"
                                           data-submit-on-change>
                                </form>
                                @else
                                    {!! $currentRate !== null ? Badge::make(number_format((float) $currentRate['hourly_rate'], 2, '.', ''))->active()->render() : '<span class="text-sm text-muted">—</span>' !!}                                @endif
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        @endif
                    <?php endforeach; ?>
                @endif
            </tbody>
        </table>
    </div>
</div>

@php
// Popup d'édition ouverte au clic sur une ligne du tableau Stores (ci-dessus, voir
// tr--clickable dans layout/app.php) : rôle et cotisations sociales du store concerné.
// Les taux personnalisés par type de shift s'éditent directement dans le tableau
// (colonne "Taux personnalisé", auto-enregistrement au changement).
foreach ($user_memberships as $m):
    $sid = (int) $m['store_id'];
    $mid = (int) $m['id'];
    $ov = $m['ded_overrides'] ?? [];
    $storeDedEnabled = !empty($m['store_ded_settings']['enabled']);
    $subject = !empty($ov['subject_to_deductions']);
    ob_start();
    if ($can('employees.update')):
    @endphp
    <form method="POST" action="{!! $BASE_URL !!}/admin/stores/{!! store_segment($sid) !!}/members/{!! $mid !!}/role" class="form-inline-flex mb-sm">
        {!! csrf_field() !!}        <div class="form-group">
            <label class="form-label">{!! __('role') !!}</label>
            <select name="role_id" class="form-control">
                @foreach ($assignable_roles as $r)
                    <option value="{!! (int) $r['id'] !!}" {!! (int) $r['id'] === (int) ($m['role_id'] ?? 0) ? 'selected' : '' !!}>{{ $r['name'] ?? '' }}</option>
                @endforeach
            </select>
        </div>
        {!! Button::make(__('apply'))->primary()->sm()->submit()->render() !!}    </form>
    @endif

    @if ($storeDedEnabled && $can('payroll.generate'))
    <form method="POST" action="{!! $BASE_URL !!}/admin/stores/{!! store_segment($sid) !!}/members/{!! $mid !!}/deductions" class="mb-sm">
        {!! csrf_field() !!}        <input type="hidden" name="_redirect_to" value="{{ $BASE_URL . '/admin/users/' . employee_segment((int) $user['id']) . '/edit?success=deductions_saved' }}">
        <label class="form-toggle form-toggle--labeled">
            <input type="hidden" name="subject_to_deductions" value="0">
            <input type="checkbox" name="subject_to_deductions" value="1" class="form-toggle__input" {!! $subject ? 'checked' : '' !!} data-submit-on-change>
            <span class="form-toggle__track"></span>
            <span>{!! __('subject_to_deductions') !!}</span>
        </label>
    </form>
    @endif

    @php
    $editMembershipFooter = '';
    if ($can('employees.update')) {
        $editMembershipFooter = '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/stores/' . store_segment($sid) . '/members/' . $mid . '/delete') . '" class="form-inline" data-confirm="' . htmlspecialchars(__('confirm_remove_member'), ENT_QUOTES) . '">'
            . csrf_field()
            . Button::make(__('remove'))->danger()->sm()->submit()->render()
            . '</form>';
    }
    echo Modal::make("editMembershipModal{$mid}")
        ->title(htmlspecialchars($m['store_name'] ?? ''))
        ->body(ob_get_clean())
        ->footer($editMembershipFooter)
        ->render();
endforeach;
@endphp

@php if (!empty($available_stores) && $can('employees.update')):
    ob_start();
    @endphp
    <form method="POST" action="{!! $BASE_URL !!}/admin/stores/0/members" id="addStoreForm" class="form-stack">
        {!! csrf_field() !!}        <input type="hidden" name="user_id" value="{!! (int)$user['id'] !!}">
        <input type="hidden" name="redirect_to" value="{!! $BASE_URL !!}/admin/users/{!! employee_segment((int)$user['id']) !!}/edit?success=member_added">
        <div class="form-group">
            <label class="form-label">{!! __('store') !!}</label>
            <select name="store_id_select" class="form-control" required data-on-change="@setFormAction" data-target="addStoreForm" data-action-template="{!! $BASE_URL !!}/admin/stores/{value}/members">
                <option value="">— {!! __('select') !!} —</option>
                @foreach ($available_stores as $s)
                    <option value="{!! (int)$s['id'] !!}">{{ $s['name'] ?? '' }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">{!! __('role') !!}</label>
            <select name="role_id" class="form-control">
                @foreach ($assignable_roles as $r)
                    <option value="{!! (int) $r['id'] !!}" {!! (int) $r['id'] === (int) $default_store_role_id ? 'selected' : '' !!}>{{ $r['name'] ?? '' }}</option>
                @endforeach
            </select>
        </div>
    </form>
    @php
    $assignStoreModalFooter = Button::make(__('add'))->primary()->submit()->attrs(['form' => 'addStoreForm'])->render()
        . ' ' . Button::make(__('cancel'))->ghost()->attrs(['data-on-click' => 'closeModal', 'data-args' => '["assignStoreModal"]'])->render();
    echo Modal::make('assignStoreModal')
        ->title(__('assign_to_store'))
        ->body(ob_get_clean())
        ->footer($assignStoreModalFooter)
        ->render();
    @endphp
@endif

<div class="card card--mt">
    <div class="card-header"><span>{!! __('reports') !!}</span></div>
    <div class="card-body">
        @if (!empty($user_memberships))
            @foreach ($user_memberships as $m)
                @php $sid = (int) $m['store_id']; @endphp
                <div class="btn-group mb-sm">
                    @php // Liens des bundles (démission, rapport de salaire… — Bundle::actions(), contrat 1.6). @endphp
                    @foreach (\App\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::EMPLOYEE_STORE, ['user_id' => (int) $user['id'], 'store_id' => $sid, 'store_name' => $m['store_name'] ?? ''], $can) as $_a)
                    <a href="{{ $_a['href'] }}" class="btn {!! $_a['style'] === 'danger' ? 'btn--danger' : ($_a['style'] === 'primary' ? 'btn--primary' : 'btn--ghost') !!} btn--sm">{!! $_a['icon'] !== '' ? $_a['icon'] . ' ' : '' !!}{{ $_a['label'] }} — {{ $m['store_name'] ?? '' }}</a>
                    @endforeach
                </div>
            @endforeach
        @else
            <p class="text-dim">{!! __('no_store_assigned') !!}</p>
        @endif
    </div>
</div>
@php endif; // mode === 'edit' (ligne 57) @endphp

<p class="form-error" data-required-error data-required-error-form="userEditForm" hidden>{!! __('required_fields_missing') !!}</p>
<div class="form-actions mt-md">
    @if ($canEditUser)
    {!! Button::make($mode === 'edit' ? __('save') : __('new_user'))->primary()->submit()->attrs(['form' => 'userEditForm'])->render() !!}    @endif
    <a href="{!! back_url(route_url('admin.users')) !!}" class="btn btn--ghost">{!! __('cancel') !!}</a>
    @if ($mode === 'edit' && $canEditUser)
    <span class="autosave-status" data-autosave-status
          data-saving-label="{{ __('autosave_saving') }}"
          data-saved-label="{{ __('autosave_saved') }}"
          data-error-label="{{ __('autosave_error') }}"></span>
    @endif
</div>

<script src="{!! $BASE_URL !!}/assets/js/modules/user-form-live-check.js"></script>
<script src="{!! $BASE_URL !!}/assets/js/modules/furigana-suggest.js"></script>
<script src="{!! $BASE_URL !!}/assets/js/modules/user-role-select.js"></script>

@endsection
