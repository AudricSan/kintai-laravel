@extends('layout.app')
@section('content')
@php
use App\View\Components\Flash;
use App\View\Components\Badge;

/** @var array  $languages */
/** @var string $instance_locale Langue de l'instance (APP_LOCALE) */
/** @var string|null $BASE_URL */

$activeCount = count(array_filter($languages, fn($l) => !empty($l['is_active'])));

echo Flash::fromSession('success', [
    'created'     => __('language_created_success'),
    'updated'     => __('language_updated_success'),
    'deleted'     => __('language_deleted_success'),
    'toggled'     => __('language_toggled_success'),
])->render();

echo Flash::fromSession('error', [
    'invalid'                        => __('language_invalid_error'),
    'not_found'                      => __('language_not_found_error'),
    'default_forbidden'              => __('language_toggle_default_forbidden'),
    'delete_default_forbidden'       => __('language_delete_default_forbidden'),
    'delete_last_active_forbidden'   => __('language_delete_last_active_forbidden'),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! __('languages') !!} <span class="page-count">({!! count($languages) !!})</span></h2>
</div>

@include('_partials._settings-tabs')

<div class="card mb-sm">
    <div class="table-wrap">
        <table class="data-table" data-mob-stack>
            <thead>
                <tr>
                    <th>{!! __('language_code') !!}</th>
                    <th>{!! __('language_name') !!}</th>
                    <th>{!! __('language_active') !!}</th>
                    <th>{!! __('language_default') !!}</th>
                    <th class="td-center">{!! __('actions') !!}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($languages as $lang)
                @php
                    $code       = $lang['code'];
                    $isDefault  = $code === ($instance_locale ?? null);
                    $isActive   = !empty($lang['is_active']);
                    $canDelete  = !$isDefault && !($isActive && $activeCount <= 1);
                @endphp
                <tr>
                    <td data-label="{{ __('language_code') }}" class="td-nowrap"><code>{{ $code }}</code></td>
                    <td data-label="{{ __('language_name') }}">
                        <form method="POST" action="{!! $BASE_URL !!}/admin/languages" class="form-inline">
                            {!! csrf_field() !!}                            <input type="hidden" name="code" value="{{ $code }}">
                            <input type="hidden" name="is_active" value="{!! $isActive ? '1' : '0' !!}">
                            <input type="text" name="name" value="{{ $lang['name'] }}"
                                   class="form-control form-control-sm lang-name-input" maxlength="100" required>
                            <button type="submit" class="btn btn--ghost btn--xs">{!! __('save') !!}</button>
                        </form>
                    </td>
                    <td data-label="{{ __('language_active') }}" class="td-center">
                        {!! $isActive ? Badge::make(__('language_active'))->success()->sm()->render() : Badge::make(__('language_inactive'))->muted()->sm()->render() !!}                        @if (!$isDefault)
                        <form method="POST" action="{!! $BASE_URL !!}/admin/languages/{{ $code }}/toggle-active" class="form-inline">
                            {!! csrf_field() !!}                            <button type="submit" class="btn btn--ghost btn--xs">{!! $isActive ? __('deactivate') : __('activate') !!}</button>
                        </form>
                        @endif
                    </td>
                    <td data-label="{{ __('language_default') }}" class="td-center">
                        @if ($isDefault)
                            {!! Badge::make(__('language_default'))->primary()->sm()->render() !!}                        @endif
                    </td>
                    <td data-label="{{ __('actions') }}" class="td-center td-nowrap">
                        <a href="{!! $BASE_URL !!}/admin/languages/{{ $code }}/edit" class="btn btn--ghost btn--xs">{!! __('edit_translations') !!}</a>
                        @if ($canDelete)
                        <form method="POST" action="{!! $BASE_URL !!}/admin/languages/{{ $code }}/delete" class="form-inline"
                              data-confirm="{{ __('language_confirm_delete') }}">
                            {!! csrf_field() !!}                            <button type="submit" class="btn btn--danger btn--xs">{!! __('delete') !!}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h3 class="card-title">{!! __('add_language') !!}</h3>
        <form method="POST" action="{!! $BASE_URL !!}/admin/languages" class="form-flex">
            <div class="form-group">
                <label class="form-label">{!! __('language_code') !!}</label>
                <input type="text" name="code" class="form-control form-control-sm" maxlength="10"
                       placeholder="es" pattern="[a-zA-Z]{2}(-[a-zA-Z]{2})?" required>
            </div>
            <div class="form-group">
                <label class="form-label">{!! __('language_name') !!}</label>
                <input type="text" name="name" class="form-control form-control-sm" maxlength="100"
                       placeholder="{!! __('language_name_example_placeholder') !!}" required>
            </div>
            <input type="hidden" name="is_active" value="1">
            <div class="form-group">
                {!! csrf_field() !!}                <button type="submit" class="btn btn--primary btn--sm">{!! __('add_language') !!}</button>
            </div>
        </form>
    </div>
</div>

@endsection
