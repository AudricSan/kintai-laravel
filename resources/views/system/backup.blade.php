@php

use App\View\Components\Button;
use App\View\Components\Card;
use App\View\Components\Table;

/**
 * @var array                                        $backups
 * @var string                                        $BASE_URL
 * @var array{type: 'success'|'danger', text: string}|null $flash
 * @var string                                        $backup_auto_enabled
 * @var int                                           $backup_max_keep
 */

$action = route_url('admin.backup');
@endphp
@if ($flash !== null)
    <div class="alert alert--{!! $flash['type'] !!} mb-sm">{{ $flash['text'] }}</div>
@endif
<div class="page-header">
    <h2 class="page-header__title">{!! __('backup_title') !!}</h2>
</div>

@includeInScope('_partials._settings-tabs')

@php
ob_start();
@endphp
<div class="form-group">
    <label class="form-label">{!! __('backup_auto_enabled') !!}</label>
    <label class="form-toggle">
        <input type="hidden" name="backup_auto_enabled" value="0">
        <input type="checkbox" name="backup_auto_enabled" value="1" class="form-toggle__input"
            {!! $backup_auto_enabled === '1' ? 'checked' : '' !!}>
        <span class="form-toggle__track"></span>
    </label>
    <p class="form-hint">{!! __('backup_auto_hint') !!}</p>
</div>
<div class="form-group">
    <label class="form-label" for="backup_max_keep">{!! __('backup_max_keep') !!}</label>
    <input type="number" id="backup_max_keep" name="backup_max_keep" class="form-control w-100"
        min="0" max="365"
        value="{!! (int) $backup_max_keep !!}">
    <p class="form-hint">{!! __('backup_max_keep_hint') !!}</p>
</div>
<div class="form-actions">
    {!! Button::make(__('save'))->primary()->submit()->render() !!}</div>
@php
$settingsBody = ob_get_clean();
$settingsForm = '<form method="POST" action="' . htmlspecialchars($action) . '/settings">' . csrf_field() . $settingsBody . '</form>';
$settingsCard = Card::make()->header(__('backup_card_title'))->body($settingsForm)->render();
@endphp

@php
ob_start();
@endphp
<form method="POST" action="{{ $action }}/create" class="form-inline">
    {!! csrf_field() !!}    <div class="form-group">
        <input type="text" name="note" class="form-control" placeholder="{!! __('backup_note_placeholder') !!}" maxlength="255">
    </div>
    {!! Button::make(__('backup_create_btn'))->primary()->submit()->render() !!}</form>
@php $createCard = Card::make()->header(__('backup_create_card'))->body(ob_get_clean())->render(); @endphp

<div class="settings-grid">
    {!! $settingsCard !!}    {!! $createCard !!}
    <div class="card card--mb settings-grid__full">
        <div class="card-header">{!! __('backup_existing') !!}</div>
        <div class="card-body">
            @if ($backups === [])
                <p class="text-muted">{!! __('backup_none') !!}</p>
            @php else:
                echo '<div class="mb-sm">'
                    . '<form method="POST" action="' . htmlspecialchars($action) . '/delete-all" class="d-inline" data-confirm="' . htmlspecialchars(sprintf(__('backup_delete_all_confirm'), count($backups)), ENT_QUOTES) . '">'
                    . csrf_field()
                    . Button::make(sprintf(__('backup_delete_all_btn'), count($backups)))->danger()->sm()->submit()->render()
                    . '</form></div>';
                echo Table::make()
                    ->data($backups)
                    ->column(__('backup_col_file'), fn($b) => '<code>' . htmlspecialchars($b['filename']) . '</code>')
                    ->text(__('backup_col_date'), fn($b) => $b['created_at'])
                    ->column(__('backup_col_size'), fn($b) => number_format((int)($b['size'] ?? 0) / 1024, 1) . ' ' . __('kb'))
                    ->text(__('backup_col_note'), fn($b) => $b['note'] ?? '')
                    ->column(__('backup_col_actions'), function ($b) use ($action) {
                        return '<form method="POST" action="' . htmlspecialchars($action) . '/restore" class="d-inline">' . csrf_field()
                            . '<input type="hidden" name="filename" value="' . htmlspecialchars($b['filename']) . '">'
                            . Button::make(__('backup_restore_btn'))->sm()->warning()->attrs(['data-confirm' => __('backup_restore_confirm')])->submit()->render()
                            . '</form>'
                            . '<form method="POST" action="' . htmlspecialchars($action) . '/delete" class="d-inline" data-confirm="' . htmlspecialchars(__('backup_delete_confirm'), ENT_QUOTES) . '">' . csrf_field()
                            . '<input type="hidden" name="filename" value="' . htmlspecialchars($b['filename']) . '">'
                            . Button::make(__('backup_delete_btn'))->sm()->danger()->submit()->render()
                            . '</form>';
                    })
                    ->render();
            endif;
            @endphp
        </div>
    </div>

    <div class="alert alert--warning settings-grid__full">
        <strong>{!! __('backup_warning_title') !!}</strong> : {!! __('backup_warning_text') !!}    </div>

    @php
    $resetAction = route_url('admin.reset.prepare');
    $optionalCategories = [
        'stores'      => ['label' => __('reset_keep_stores'), 'hint' => __('reset_keep_stores_hint')],
        'shift_types' => ['label' => __('reset_keep_shift_types'), 'hint' => __('reset_keep_shift_types_hint')],
        'ui_prefs'    => ['label' => __('reset_keep_ui_prefs'), 'hint' => __('reset_keep_ui_prefs_hint')],
    ];
    ob_start();
    @endphp
    <p class="text-muted mb-sm">{!! __('reset_intro') !!}</p>
    <form method="POST" action="{{ $resetAction }}" class="form-stack" data-confirm="{{ __('reset_data_confirm') }}">
        {!! csrf_field() !!}        <p class="form-label">{!! __('reset_keep_label') !!}</p>
        @foreach ($optionalCategories as $key => $cat)
            <div class="form-group">
                <label class="form-toggle">
                    <input type="checkbox" name="keep[]" value="{{ $key }}" class="form-toggle__input">
                    <span class="form-toggle__track"></span>
                </label>
                <span>{{ $cat['label'] }}</span>
                <p class="form-hint">{{ $cat['hint'] }}</p>
            </div>
        @endforeach
        <div class="form-actions">
            <input type="hidden" name="mode" value="data">
            {!! Button::make(__('reset_data_btn'))->danger()->submit()->render() !!}        </div>
    </form>
    <form method="POST" action="{{ $resetAction }}" class="mt-sm" data-confirm="{{ __('reset_factory_confirm') }}">
        {!! csrf_field() !!}        <input type="hidden" name="mode" value="factory">
        {!! Button::make(__('reset_factory_btn'))->danger()->submit()->render() !!}        <p class="form-hint">{!! __('reset_factory_hint') !!}</p>
    </form>
    @php echo Card::make()->header(__('reset_title'))->body(ob_get_clean())->attrs(['class' => 'settings-grid__full'])->render(); @endphp
</div>