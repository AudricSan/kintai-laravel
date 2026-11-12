<?php

namespace App\Http\Controllers;

use App\Actions\Ical\ResolveIcalFeed;
use App\Ical\IcalFeedBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Flux iCal d'un employé pour un magasin (`/ical/{jeton}/{magasin}/shifts.ics`). Route publique : le jeton fait foi,
 * aucune session ni aucun cookie. Toutes les causes d'échec renvoient exactement la même réponse 404.
 */
class IcalFeedController extends Controller
{
    public function __invoke(Request $request, string $token, string $store, ResolveIcalFeed $resolve, IcalFeedBuilder $builder): Response
    {
        $feed = ctype_digit($store) ? $resolve->handle($token, (int) $store) : null;

        if ($feed === null) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = $builder->build($feed['user'], $feed['store']);

        $response = response($content, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="kintai-'.preg_replace('/[^A-Za-z0-9_-]/', '', $feed['store']->code).'-shifts.ics"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Robots-Tag' => 'noindex',
        ]);

        // L'ETag ignore DTSTAMP (qui peut varier) : un agenda inchangé reçoit un 304 aux relectures fréquentes.
        $response->setEtag(md5((string) preg_replace('/^DTSTAMP:.*\r?\n/m', '', $content)));
        $response->isNotModified($request);

        return $response;
    }
}
