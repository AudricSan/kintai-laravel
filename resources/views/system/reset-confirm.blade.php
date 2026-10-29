@extends('layout.app')
@section('content')
@php
use App\View\Components\Button;
use App\View\Components\Card;

/**
 * @var string   $mode
 * @var string[] $keep
 * @var string   $backup_filename
 * @var string   $download_url
 */

$executeAction = route_url('admin.reset.execute');
$backAction    = route_url('admin.backup');
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! __('reset_title') !!}</h2>
</div>

<div class="alert alert--success mb-sm">
    {!! __('reset_backup_created') !!} <code>{{ $backup_filename }}</code>
    — <a href="{{ $download_url }}" id="reset-download-link">{!! __('reset_download_now') !!}</a>
</div>

@php
ob_start();
@endphp
<p>{!! $mode === 'factory' ? __('reset_factory_summary') : __('reset_data_summary') !!}</p>
@if ($mode === 'data' && $keep !== [])
    <p class="text-muted">{!! __('reset_keep_summary') !!} : {{ implode(', ', array_map(
        fn($k) => __('reset_keep_' . $k),
        $keep
    )) }}</p>
@endif

<form method="POST" action="{{ $executeAction }}"
      data-confirm="{{ $mode === 'factory' ? __('reset_factory_confirm') : __('reset_data_confirm') }}">
    {!! csrf_field() !!}    <input type="hidden" name="mode" value="{{ $mode }}">
    <input type="hidden" name="backup_filename" value="{{ $backup_filename }}">
    @foreach ($keep as $k)
        <input type="hidden" name="keep[]" value="{{ $k }}">
    @endforeach
    <div class="form-actions">
        {!! Button::make(__('reset_confirm_btn'))->danger()->submit()->render() !!}        <a href="{{ $backAction }}" class="btn btn--ghost">{!! __('cancel') !!}</a>
    </div>
</form>
@php echo Card::make()->header(__('reset_confirm_card_title'))->body(ob_get_clean())->render(); @endphp

<script nonce="{!! csp_nonce() !!}">document.getElementById('reset-download-link').click();</script>

@endsection
