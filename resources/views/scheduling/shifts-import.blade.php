@extends('layout.app')
@section('content')
@php
use App\View\Components\Button;
use App\View\Components\Card;
use App\View\Components\Flash;

/** @var array       $all_stores */
/** @var string|null $error */

echo Flash::fromQuery('error', [
    'upload' => __('upload_error'),
    'parse'  => __('parse_error'),
    'empty'  => __('empty_file_error'),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! __('import_excel_title') !!}</h2>
    <a href="{!! back_url(route_url('admin.shifts')) !!}" class="btn btn--ghost">← {!! __('back') !!}</a>
</div>

@php
$action = route_url('admin.shifts.import');
ob_start();
@endphp
<form method="POST" action="{!! $action !!}" enctype="multipart/form-data">
    {!! csrf_field() !!}    <div class="form-group">
        <label class="form-label">{!! __('destination_store') !!} <span class="required">*</span></label>
        <select name="store_id" class="form-control" required>
            <option value="">— {!! __('select') !!} —</option>
            @foreach ($all_stores as $s)
                <option value="{!! (int) $s['id'] !!}">{{ $s['name'] ?? '' }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group import-form-group--mt">
        <label class="form-label">{!! __('excel_file_label') !!} <span class="required">*</span></label>
        <input type="file" name="excel_file" accept=".xlsx,.xlsm" required>
        <div class="form-hint">{!! __('excel_format_hint') !!}</div>
    </div>
    <div class="import-form-footer">
        {!! Button::make(__('analyze_file'))->primary()->submit()->render() !!}    </div>
</form>
@php
$body = ob_get_clean();
echo Card::make()->narrow()->header(__('excel_file_label'))->body($body)->render();
@endphp

@endsection
