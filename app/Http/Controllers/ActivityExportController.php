<?php

namespace App\Http\Controllers;

use App\Activity\ActivityActionRegistry;
use App\Activity\ActivityDetails;
use App\Activity\ActivityLogger;
use App\Activity\ActivityQuery;
use App\Models\ActivityEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV du journal : mêmes filtres et même restriction par magasin que l'écran. Séparateur « ; » et BOM UTF-8
 * pour Excel, 20 000 lignes au plus, écrit au fil de l'eau. L'export lui-même est journalisé.
 */
class ActivityExportController extends Controller
{
    public const MAX_ROWS = 20000;

    public function __invoke(Request $request, ActivityQuery $activity, ActivityActionRegistry $actions, ActivityLogger $logger): StreamedResponse
    {
        $user = $request->user();
        $filters = array_filter($request->only(ActivityQuery::FILTERS), fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        // Les entrées écrites après ce point (dont la ligne de l'export lui-même) restent hors du fichier.
        $lastId = (int) ActivityEntry::query()->max('id');
        $query = $activity->for($user, $filters)->where('id', '<=', $lastId);
        $count = min(self::MAX_ROWS, (clone $query)->count());

        $logger->record('activity.exported', null, null, ['count' => $count, 'filters' => $filters], $user);

        $timezone = (string) config('kintai.timezone');

        return response()->streamDownload(function () use ($query, $actions, $timezone): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $this->write($out, [__('Date'), __('User'), __('Action'), __('Action key'), __('Target'), __('Store'), __('Details'), __('IP address')]);

            $written = 0;
            foreach ($query->with(['actor', 'store'])->lazy(500) as $entry) {
                if ($written++ >= self::MAX_ROWS) {
                    break;
                }

                $this->write($out, [
                    $entry->created_at?->setTimezone($timezone)->format('Y-m-d H:i:s') ?? '',
                    $entry->actor->name ?? '',
                    $actions->label($entry->action),
                    $entry->action,
                    ActivityDetails::target($entry),
                    $entry->store->name ?? '',
                    ActivityDetails::text($entry),
                    $entry->ip_address ?? '',
                ]);
            }

            fclose($out);
        }, 'activity-log-'.now()->setTimezone($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private function write($out, array $cells): void
    {
        // Un texte qui commence par = + - @ serait lu comme une formule par le tableur.
        $cells = array_map(fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell, $cells);

        fputcsv($out, $cells, ';', '"', '');
    }
}
