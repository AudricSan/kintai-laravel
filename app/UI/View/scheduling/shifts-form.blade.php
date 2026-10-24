@php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * @var string $mode 'create'|'edit'
 * @var array  $shift
 * @var array  $all_users
 * @var array  $all_stores
 * @var array  $all_types
 * @var string $redirect_to
 */

$redirect_to = trim($redirect_to ?? '');
// Employé non disponible ce jour-là (avertissement, jamais un blocage) : encadré rouge, Annuler en action principale.
$availability_conflict = $availability_conflict ?? null;
$availability_message  = $availability_message ?? '';
$action = $mode === 'edit'
    ? $BASE_URL . '/admin/shifts/' . (int) $shift['id'] . '/edit'
    : route_url('admin.shifts.create');
if ($redirect_to !== '') {
    $sep = str_contains($action, '?') ? '&' : '?';
    $action .= $sep . 'redirect_to=' . urlencode($redirect_to);
}

echo Flash::fromQuery('error', [
    'timeoff_conflict' => __('shift_timeoff_conflict'),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! $mode === 'edit' ? __('edit_shift') : __('new_shift') !!}</h2>
    <div class="page-header__actions">
        <a href="{!! back_url(route_url('admin.shifts')) !!}" class="btn btn--ghost">← {!! __('back') !!}</a>
    </div>
</div>

@php
ob_start();
@endphp
<form method="POST" action="{{ $action }}">
    {!! csrf_field() !!}    @if ($redirect_to !== '')
        <input type="hidden" name="redirect_to" value="{{ $redirect_to }}">
    @endif
    @if ($availability_conflict !== null)
        <div class="alert alert--danger availability-warning" role="alert">
            <strong>{{ __('shift_availability_title') }}</strong>
            <p>{{ $availability_message }}</p>
        </div>
    @endif
    @includeInScope('_partials._form-shift')
    @if ($availability_conflict !== null)
    <div class="form-actions">
        <a href="{!! back_url(route_url('admin.shifts')) !!}" class="btn btn--primary">{!! __('cancel') !!}</a>
        {!! Button::make(__('shift_availability_force'))->ghost()->submit()->attrs(['name' => 'force_availability', 'value' => '1'])->render() !!}    </div>
    @else
    <div class="form-actions">
        {!! Button::make($mode === 'edit' ? __('save') : __('new_shift'))->primary()->submit()->render() !!}        @php if ($mode === 'edit'): // Boutons des bundles (bourse aux shifts… — contrat 1.17), dans ce formulaire (formaction). @endphp
            {!! \kintai\Core\Extension\ShiftFormSections::current()->renderActions($shift, $feat) !!}        @endif
        <a href="{!! back_url(route_url('admin.shifts')) !!}" class="btn btn--ghost">{!! __('cancel') !!}</a>
        @if ($mode === 'edit')
            @php // Dans le formulaire du shift : formaction (un <form> imbriqué était ignoré, le bouton enregistrait le shift). @endphp
            <span class="ml-auto">{!! Button::make(__('delete'))->danger()->submit()->attrs(['data-confirm' => __('confirm'), 'formaction' => $BASE_URL . '/admin/shifts/' . (int) $shift['id'] . '/delete', 'formmethod' => 'post', 'formnovalidate' => 'formnovalidate'])->render() !!}</span>
        @endif
    </div>
    @endif
</form>
@php
echo Card::make()->body(ob_get_clean())->render();
@endphp
<script src="{!! $BASE_URL !!}/assets/js/modules/shift-wage-preview.js"></script>
