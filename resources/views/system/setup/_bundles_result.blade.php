@php
use App\View\Components\Button;
use App\View\Components\Card;

/** @var array<string, mixed> $result bilan de l'installation (installed, inactive, failed) */
ob_start();
@endphp
    @if (!empty($result['installed']))
        <div class="alert alert--success mb-sm">{{ __('setup_done_installed', ['list' => implode(', ', $result['installed'])]) }}</div>
    @endif
    @if (!empty($result['inactive']))
        <div class="alert alert--warning mb-sm">{{ __('setup_done_inactive', ['list' => implode(', ', $result['inactive'])]) }}</div>
    @endif
    @if (!empty($result['pending']))
        <div class="alert alert--info mb-sm">
            {{ __('setup_pending_waiting', ['list' => implode(', ', $result['pending'])]) }}            <a href="{!! route_url('admin.setup') !!}?step=license">{!! __('setup_sel_activate_license') !!}</a>
        </div>
    @endif
    @if (!empty($result['failed']))
        <div class="alert alert--warning mb-sm">
            {{ __('setup_done_failed') }}            <ul class="text-sm">
                @foreach ($result['failed'] as $slug => $reason)
                    <li><strong>{{ (string) $slug }}</strong> — {{ (string) $reason }}</li>
                @endforeach
            </ul>
            <a href="{!! route_url('admin.bundles.market') !!}">{{ __('setup_done_open_market') }}</a>
        </div>
    @endif
    <div class="form-actions">
        {!! Button::make(__('setup_continue'))->primary()->link(route_url('admin.setup') . '?step=' . $next)->render() !!}    </div>
@php echo Card::make()->header(__('setup_bundles_title'))->body(ob_get_clean())->render(); @endphp
