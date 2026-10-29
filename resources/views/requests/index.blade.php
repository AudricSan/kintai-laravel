@extends('layout.app')
@section('content')
@php
/**
 * Page « Demandes » : une section par sorte de demande en attente, fournie par App\Extension\PendingRequests
 * (Bundle::pendingRequestsProviders(), contrat 1.9, ou repli historique du Core).
 *
 * @var list<array{key: string, label: string, empty_label: string, manage_url: string, requests: list<\kintai\Contract\Provider\PendingRequest>}> $sections
 * @var array $users_map
 * @var array $stores_map
 */
use kintai\Contract\Provider\PendingRequest;

$sections ??= [];
$_total = array_sum(array_map(fn(array $s): int => count($s['requests']), $sections));

// Valeur d'une cellule selon le style de sa colonne (voir PendingRequest).
$_cell = static function (array $column) use ($users_map, $stores_map): string {
    $value = $column['value'] ?? null;
    return match ($column['style'] ?? PendingRequest::TEXT) {
        PendingRequest::USER  => htmlspecialchars($users_map[(int) $value] ?? ('User #' . (int) $value)),
        PendingRequest::STORE => htmlspecialchars($stores_map[(int) $value] ?? ('Store #' . (int) $value)),
        PendingRequest::TIME  => htmlspecialchars(substr((string) $value, 0, 5) ?: '—'),
        PendingRequest::BADGE => '<span class="badge badge--pending">' . htmlspecialchars((string) $value) . '</span>',
        default               => htmlspecialchars((string) $value),
    };
};
@endphp

<div class="page-header">
    <h2 class="page-header__title">{!! __('requests') !!} <span class="page-count">({!! $_total !!})</span></h2>
</div>

@if ($sections === [])
    <div class="card card--mt">
        <div class="empty-state">{!! __('no_data') !!}</div>
    </div>
@endif

@foreach ($sections as $_section)
<div class="card card--mt">
    <div class="card-header">
        <span>{!! __($_section['label']) !!}</span>
        @if ($_section['manage_url'] !== '')
        <a href="{{ $_section['manage_url'] }}" class="card-header-link">{!! __('view_all') !!}</a>
        @endif
    </div>
    @if ($_section['requests'] === [])
        <div class="empty-state">{!! __($_section['empty_label']) !!}</div>
    @else
        <div class="table-wrap">
            <table class="data-table" data-mob-stack>
                <thead>
                    <tr>@foreach ($_section['requests'][0]->columns as $_col)<th>{{ __($_col['label']) }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach ($_section['requests'] as $_req)
                        <tr class="tr--clickable" data-goto="{{ $_req->url ?? $_section['manage_url'] }}">
                            @foreach ($_req->columns as $_col)
                            <td data-label="{{ __($_col['label']) }}">{!! $_cell($_col) !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endforeach
@php unset($_total, $_cell, $_section, $_req, $_col); @endphp

@endsection
