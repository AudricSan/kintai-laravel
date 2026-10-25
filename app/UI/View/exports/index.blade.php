@php
/**
 * Page « Exports » : choix du jeu de données et de la cible (moi, un magasin, tous mes magasins) dans un premier formulaire
 * qui se recharge au changement, puis personnes, période et format dans le second, qui télécharge le fichier.
 *
 * @var array                                              $available  clé => ['dataset' => ExportDataset, 'stores' => list<int>, 'self' => bool]
 * @var \kintai\Contract\Extension\ExportDataset|null      $dataset    jeu de données choisi
 * @var array<string, string>                              $targets    valeur => libellé
 * @var string                                             $target     cible choisie (me, all, store:ID)
 * @var list<array<string, mixed>>                         $candidates personnes proposables (magasin choisi)
 * @var string                                             $from
 * @var string                                             $to
 * @var bool                                               $canIcs
 * @var string                                             $BASE_URL
 */

use kintai\UI\Components\Card;
use kintai\UI\Components\UserPicker;

$notice = in_array($_GET['error'] ?? '', ['forbidden', 'ics_scope'], true) ? (string) $_GET['error'] : '';
@endphp

<div class="page-header">
    <h2 class="page-header__title">{!! __('export_title') !!}</h2>
</div>

@if ($notice !== '')
    <div class="alert alert--warning mb-sm">{!! __('export_error_' . $notice) !!}</div>
@endif

@if ($available === [] || $dataset === null)
    <p class="text-muted">{!! __('export_nothing') !!}</p>
@else
    @php ob_start(); @endphp
    <p class="text-muted mb-sm">{!! __('export_description') !!}</p>

    <form method="GET" action="{!! $BASE_URL !!}/exports" class="form-stack">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="export-dataset">{!! __('export_dataset') !!}</label>
                <select class="form-control" id="export-dataset" name="dataset" data-submit-on-change>
                    @foreach ($available as $key => $entry)
                        <option value="{{ (string) $key }}"{!! $key === $dataset->key ? ' selected' : '' !!}>{{ __($entry['dataset']->label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="export-target">{!! __('export_target') !!}</label>
                <select class="form-control" id="export-target" name="target" data-submit-on-change>
                    @foreach ($targets as $value => $label)
                        <option value="{{ (string) $value }}"{!! $value === $target ? ' selected' : '' !!}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </form>

    <form method="GET" action="{!! $BASE_URL !!}/exports/download" class="form-stack">
        <input type="hidden" name="dataset" value="{{ $dataset->key }}">
        <input type="hidden" name="target" value="{{ $target }}">

        @if ($candidates !== [])
            <div class="form-group">
                {!! UserPicker::make('user_ids', $candidates)
                    ->legend(__('export_people'))
                    ->hint(__('export_people_hint'))
                    ->render() !!}            </div>
        @endif

        @if ($dataset->hasPeriod)
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="export-from">{!! __('export_from') !!}</label>
                    <input type="date" class="form-control" id="export-from" name="from" value="{{ $from }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="export-to">{!! __('export_to') !!}</label>
                    <input type="date" class="form-control" id="export-to" name="to" value="{{ $to }}">
                </div>
            </div>
        @endif

        <div class="form-inline-flex">
            <button type="submit" name="format" value="xlsx" class="btn btn--primary btn--sm">{!! __('export_format_xlsx') !!}</button>
            <button type="submit" name="format" value="csv" class="btn btn--ghost btn--sm">{!! __('export_format_csv') !!}</button>
            <button type="submit" name="format" value="json" class="btn btn--ghost btn--sm">{!! __('export_format_json') !!}</button>
            @if ($canIcs)
                <button type="submit" name="format" value="ics" class="btn btn--ghost btn--sm">{!! __('export_format_ics') !!}</button>
            @endif
        </div>
        @if ($canIcs)
            <p class="text-xs text-muted">{!! __('export_ics_hint') !!}</p>
        @endif
    </form>
    @php
    echo Card::make(__('export_card'))->body(ob_get_clean())->render();
    @endphp
@endif
