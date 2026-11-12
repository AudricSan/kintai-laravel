<?php

namespace App\Http\Controllers;

use App\Actions\Exports\ExportOwnSchedule;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Exporter mon planning » (`/settings/schedule-export?store=&format=csv|json|ics&from=&to=`) : toujours le planning de
 * l'utilisateur connecté, dans un magasin dont il est membre.
 */
class ScheduleExportController extends Controller
{
    public function __invoke(Request $request, ExportOwnSchedule $export): StreamedResponse
    {
        $user = $request->user();
        $store = $user->stores()->where('stores.id', (int) $request->query('store'))->first();
        abort_if($store === null, 404);

        $from = $request->query('from');
        $to = $request->query('to');

        return $export->handle(
            $user,
            $store,
            strtolower((string) $request->query('format', '')),
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );
    }
}
