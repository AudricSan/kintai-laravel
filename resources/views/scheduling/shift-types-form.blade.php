@extends('layout.app')
@section('content')
@php
use App\View\Components\Button;
use App\View\Components\Card;
use App\View\Components\Flash;

/**
 * @var string $mode 'create'|'edit'
 * @var array  $shift_type
 * @var array  $all_stores
 */
$action = $mode === 'edit'
    ? $BASE_URL . '/admin/shift-types/' . (int) $shift_type['id'] . '/edit'
    : route_url('admin.shift_types.create');

echo Flash::fromQuery('error', [
    'store_required' => __('val_store_required'),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! $mode === 'edit' ? __('edit_shift_type') : __('new_shift_type') !!}</h2>
    <div class="page-header__actions">
        <a href="{!! back_url(route_url('admin.shift_types')) !!}" class="btn btn--ghost">← {!! __('back') !!}</a>
    </div>
</div>

@php
ob_start();
echo '<form method="POST" action="' . htmlspecialchars($action) . '">';
echo csrf_field();
echo $__env->make('_partials._form-shift-type', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render();
echo '<div class="form-actions">';
echo Button::make($mode === 'edit' ? __('save') : __('new_type'))->primary()->submit()->render();
echo ' <a href="' . back_url(route_url('admin.shift_types')) . '" class="btn btn--ghost">' . __('cancel') . '</a>';
echo '</div></form>';
echo Card::make()->body(ob_get_clean())->render();
@endphp

@endsection
