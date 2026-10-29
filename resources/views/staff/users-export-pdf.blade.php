@php
/**
 * Template HTML for mPDF — Employee export (item 3).
 * Rendered standalone (no layout) : soit pour la génération PDF serveur,
 * soit directement comme aperçu navigateur (avec barre d'outils) quand
 * $downloadUrl est fourni — voir AdminUserController::exportUsersPdf() vs
 * exportUsersPdfDownload().
 *
 * @var array       $rows          Voir AdminUserController::usersForExport()
 * @var string      $generated_at
 * @var string|null $downloadUrl
 */
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style nonce="{!! csp_nonce() !!}">
@php
echo file_get_contents(BASE_PATH . '/public/assets/css/pdf/pdf-brand.css');
echo file_get_contents(BASE_PATH . '/public/assets/css/pdf/pdf-preview.css');
echo file_get_contents(BASE_PATH . '/public/assets/css/pdf/pdf-export-table.css');
@endphp
</style>
</head>
<body>

@include('_partials._pdf-preview-toolbar')
<div class="pdf-preview-page pdf-preview-page--landscape">

<h1>{!! __('users') !!}</h1>
<div class="subtitle">{!! count($rows) !!} — {{ $generated_at }}</div>

<table>
    <tr>
        <th>{!! __('employee_number') !!}</th>
        <th>{!! __('name') !!}</th>
        <th>{!! __('email') !!}</th>
        <th>{!! __('phone') !!}</th>
        <th>{!! __('mobile_phone') !!}</th>
        <th>{!! __('address') !!}</th>
        <th>{!! __('store') !!}</th>
        <th>{!! __('status') !!}</th>
        <th>{!! __('hourly_rate') !!}</th>
    </tr>
    @foreach ($rows as $row)
    <tr>
        <td>{{ $row['employee_code'] ?? '—' }}</td>
        <td>{{ $row['display_name'] ?: trim($row['last_name'] . ' ' . $row['first_name']) }}</td>
        <td>{{ $row['email'] }}</td>
        <td>{{ $row['phone'] ?? '—' }}</td>
        <td>{{ $row['mobile_phone'] ?? '—' }}</td>
        <td>{!! nl2br(htmlspecialchars(trim(($row['postal_code'] ? $row['postal_code'] . ' ' : '') . ($row['address'] ?? '')))) ?: '<span class="text-muted">—</span>' !!}</td>
        <td>{!! !empty($row['stores']) ? htmlspecialchars(implode(', ', $row['stores'])) : '<span class="text-muted">—</span>' !!}</td>
        <td>{!! $row['is_active'] ? __('active') : __('inactive') !!}</td>
        <td>
            @if (empty($row['hourly_rates']))
                <span class="text-muted">—</span>
            @else
                @foreach ($row['hourly_rates'] as $rate)
                    {{ $rate['shift_type'] }}: {!! number_format($rate['hourly_rate'], 0) !!}<br>
                @endforeach
            @endif
        </td>
    </tr>
    @endforeach
</table>

<div class="footer">
    {!! __('pdf_generated_by') !!} Kintai — {{ $generated_at }}</div>

</div>
</body>
</html>
