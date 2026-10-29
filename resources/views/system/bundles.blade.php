@extends('layout.app')
@section('content')
@php
use App\View\Components\Button;
use App\View\Components\Flash;

/** @var array $bundles Liste de ['key' => string, 'label' => string, 'desc' => string, 'enabled' => bool, 'official' => bool] */
/** @var bool  $success */
/** @var int|null $maxActiveBundles */
/** @var int  $activeCount */
/** @var string|null $BASE_URL */

$maxActiveBundles ??= null;
$activeCount      ??= 0;

echo Flash::fromQuery('success', ['default' => __('save_success')])->render();
echo Flash::fromQuery('error', [
    'bundle_quota_exceeded' => __('bundle_quota_exceeded', ['max' => $maxActiveBundles]),
])->render();
@endphp
<div class="page-header">
    <h2 class="page-header__title">{!! __('bundle_settings') !!}</h2>
</div>

@include('_partials._settings-tabs')
@include('system._bundle-tabs')

<div class="card card--mb">
    <div class="card-body">
        <p class="form-hint">{!! __('bundle_settings_hint') !!}</p>
        @if ($maxActiveBundles !== null)
            <p class="form-hint">{!! __('bundle_active_count', ['count' => $activeCount, 'max' => $maxActiveBundles]) !!}</p>
        @endif
    </div>
</div>

<form method="POST" action="{!! route_url('admin.bundles') !!}">
    {!! csrf_field() !!}
    <div class="card">
        <div class="card-body form-stack">
            <div class="feature-cards">
                @if (empty($bundles))
                    <p class="form-hint">{!! __('bundle_none_found') !!}</p>
                @endif
                @foreach ($bundles as $b)
                    <label class="feature-card">
                        <input type="checkbox" name="bundle_{{ $b['key'] }}" value="1"
                               {!! $b['enabled'] ? 'checked' : '' !!} class="feature-card__input">
                        <div class="feature-card__body">
                            <span class="feature-card__title">
                                {{ $b['label'] }}                                <span class="badge badge--neutral badge--sm">{{ __('bundle_version', ['version' => $b['version']]) }}</span>
                                @if (!$b['official'])
                                    <span class="badge badge--warning badge--sm">{!! __('bundle_third_party') !!}</span>
                                @endif
                                @if (!empty($b['incompatible']))
                                    <span class="badge badge--danger badge--sm">{!! __('bundle_incompatible') !!}</span>
                                @endif
                            </span>
                            <span class="feature-card__desc">{{ $b['desc'] }}</span>
                            @if (!empty($b['incompatible']))
                                <span class="form-hint text-danger">{{ __('bundle_incompatible_not_loaded', ['reason' => $b['incompatible']]) }}</span>
                            @endif
                            @if (!$b['official'])
                                <span class="form-hint">{!! __('bundle_third_party_warning') !!}</span>
                            @endif
                        </div>
                        <div class="feature-card__toggle">
                            <span class="toggle-switch">
                                <span class="toggle-switch__track"></span>
                                <span class="toggle-switch__thumb"></span>
                            </span>
                            <span class="toggle-switch__label">
                                {!! $b['enabled'] ? __('active') : __('inactive') !!}                            </span>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="form-actions">
        {!! Button::make(__('save'))->primary()->submit()->render() !!}    </div>
</form>

@endsection
