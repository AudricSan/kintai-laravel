@php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/** @var array<string, mixed> $stepData */
$action = route_url('admin.setup.step', ['step' => 'store']);
ob_start();
@endphp
<p class="mb-sm">{!! __('setup_store_intro') !!}</p>
@if (!empty($stepData['existing']))
    <div class="alert alert--info mb-sm">{{ __('setup_store_existing', ['list' => implode(', ', $stepData['existing'])]) }}</div>
@endif
<form method="POST" action="{{ $action }}" class="form-stack">
    {!! csrf_field() !!}    <div class="form-group">
        <label class="form-label" for="setup-store-name">{!! __('name') !!}</label>
        <input type="text" id="setup-store-name" name="name" class="form-control" maxlength="100" required>
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-store-code">{!! __('store_code') !!}</label>
        <input type="text" id="setup-store-code" name="code" class="form-control" maxlength="20" placeholder="{{ __('setup_store_code_auto') }}">
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-store-timezone">{!! __('timezone') !!}</label>
        <select id="setup-store-timezone" name="timezone" class="form-control">
            @foreach ($stepData['timezones'] as $_tz)
                <option value="{{ $_tz }}"{!! $_tz === $stepData['defaults']['timezone'] ? ' selected' : '' !!}>{{ $_tz }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-store-currency">{!! __('currency') !!}</label>
        <select id="setup-store-currency" name="currency" class="form-control">
            @foreach ($stepData['currencies'] as $_cur)
                <option value="{{ $_cur }}"{!! $_cur === $stepData['defaults']['currency'] ? ' selected' : '' !!}>{{ $_cur }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-store-street">{!! __('address') !!}</label>
        <input type="text" id="setup-store-street" name="address_street" class="form-control">
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-store-city">{!! __('city') !!}</label>
        <input type="text" id="setup-store-city" name="address_city" class="form-control">
    </div>
    <div class="form-actions">
        {!! Button::make(__('setup_store_create'))->primary()->submit()->render() !!}    </div>
</form>
@includeInScope('system.setup._skip')
@php echo Card::make()->header(__('setup_step_store'))->body(ob_get_clean())->render(); @endphp
