@php

use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/**
 * Liaison Google Calendar (onglet iCal du profil). Rien n'est affiché tant que l'Owner n'a pas activé la fonction.
 *
 * @var array{available: bool, connection: ?array<string, mixed>} $calendar
 * @var array<int, array<string, mixed>>                         $stores   magasins de l'utilisateur (id => ligne)
 */

if (empty($calendar['available']) && empty($calendar['connection'])) {
    return;
}
$_conn = $calendar['connection'];

/** Choix du nom de l'agenda, commun à la liaison et aux options. */
$_nameChoice = function (string $mode, int $storeId) use ($stores): string {
    ob_start(); @endphp
    <div class="form-group">
        <label class="form-label" for="gcal_name_mode">{!! __('gcal_name_label') !!}</label>
        <select id="gcal_name_mode" name="calendar_name_mode" class="form-control">
            <option value="instance"{!! $mode === 'instance' ? ' selected' : '' !!}>{!! __('gcal_name_instance') !!}</option>
            @if ($stores !== [])
            <option value="store"{!! $mode === 'store' ? ' selected' : '' !!}>{!! __('gcal_name_store') !!}</option>
            @endif
        </select>
    </div>
    @if ($stores !== [])
    <div class="form-group">
        <label class="form-label" for="gcal_store_id">{!! __('gcal_name_store_choose') !!}</label>
        <select id="gcal_store_id" name="store_id" class="form-control">
            <?php foreach ($stores as $_s): ?>
            <option value="{!! (int) $_s['id'] !!}"{!! (int) $_s['id'] === $storeId ? ' selected' : '' !!}>{{ (string) $_s['name'] }}</option>
            <?php endforeach; ?>
        </select>
    </div>
    @php endif;
    return (string) ob_get_clean();
};

ob_start();
@endphp
<p class="text-muted mb-sm">{!! __('gcal_intro') !!}</p>
<p class="text-muted mb-sm">{!! __('gcal_privacy_note') !!}</p>

@if ($_conn === null)
    <form method="POST" action="{!! route_url('profile.calendar.connect') !!}">
        {!! csrf_field() !!}        {!! $_nameChoice('instance', (int) array_key_first($stores)) !!}        {!! Button::make(__('gcal_connect'))->primary()->submit()->render() !!}    </form>
@else
    @if ($_conn['status'] !== 'active')
        {!! Alert::make(__('gcal_revoked_notice'))->danger()->render() !!}    @endif
    <dl class="gcal-details mb-sm">
        <dt>{!! __('gcal_status') !!}</dt>
        <dd>{!! ($_conn['status'] === 'active' ? Badge::make(__('gcal_status_active'))->success() : Badge::make(__('gcal_status_revoked'))->danger())->render() !!}</dd>
        @if ($_conn['email'] !== '')
        <dt>{!! __('gcal_account') !!}</dt>
        <dd>{{ $_conn['email'] }}</dd>
        @endif
        @if ($_conn['calendar_name'] !== '')
        <dt>{!! __('gcal_calendar') !!}</dt>
        <dd>{{ $_conn['calendar_name'] }}</dd>
        @endif
        <dt>{!! __('gcal_last_sync') !!}</dt>
        <dd>{!! $_conn['last_sync_at'] ? htmlspecialchars((string) $_conn['last_sync_at']) : __('gcal_never_synced') !!}</dd>
        @if (!empty($_conn['last_error']) && $_conn['status'] === 'active')
        <dt>{!! __('gcal_last_error') !!}</dt>
        <dd class="text-muted">{{ (string) $_conn['last_error'] }}</dd>
        @endif
    </dl>

    @if ($_conn['status'] === 'active')
    <form method="POST" action="{!! route_url('profile.calendar.options') !!}" class="mb-sm">
        {!! csrf_field() !!}        {!! $_nameChoice($_conn['name_mode'], $_conn['store_id']) !!}        {!! Button::make(__('gcal_save_options'))->ghost()->sm()->submit()->render() !!}    </form>
    <div class="gcal-actions">
        <form method="POST" action="{!! route_url('profile.calendar.resync') !!}">
            {!! csrf_field() !!}            {!! Button::make(__('gcal_resync'))->ghost()->sm()->submit()->render() !!}        </form>
    @else
    <form method="POST" action="{!! route_url('profile.calendar.connect') !!}" class="mb-sm">
        {!! csrf_field() !!}        {!! $_nameChoice($_conn['name_mode'], $_conn['store_id']) !!}        {!! Button::make(__('gcal_reconnect'))->primary()->submit()->render() !!}    </form>
    <div class="gcal-actions">
    @endif
        <form method="POST" action="{!! route_url('profile.calendar.disconnect') !!}"
              data-confirm="{{ __('gcal_disconnect_confirm') }}">
            {!! csrf_field() !!}            <label class="form-check">
                <input type="checkbox" name="delete_events" value="1">
                <span>{!! __('gcal_disconnect_delete_events') !!}</span>
            </label>
            {!! Button::make(__('gcal_disconnect'))->danger()->sm()->submit()->render() !!}        </form>
    </div>
@endif
@php
echo Card::make(__('gcal_card_title'))->body(ob_get_clean())->render();
@endphp