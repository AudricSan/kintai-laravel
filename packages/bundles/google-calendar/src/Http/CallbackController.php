<?php

namespace Kintai\Bundles\GoogleCalendar\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Kintai\Bundles\GoogleCalendar\CalendarSync;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarClient;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarException;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarSettings;
use Kintai\Bundles\GoogleCalendar\GoogleRedirectUri;
use Kintai\Bundles\GoogleCalendar\Http\Requests\CallbackRequest;
use Kintai\Bundles\GoogleCalendar\OAuthState;
use Kintai\Contracts\Activity\ActivityRecorder;

/**
 * GET /google-calendar/callback : retour de Google (autorisation donnée, refusée ou expirée). Chacun ne lie que son propre
 * agenda : l'identifiant de l'utilisateur vient de la session, jamais de la requête. Le `state` doit être celui que la
 * session a émis (anti-CSRF) ; il est à usage unique.
 */
class CallbackController extends Controller
{
    public function __invoke(
        CallbackRequest $request,
        GoogleCalendarSettings $settings,
        GoogleCalendarClient $client,
        GoogleRedirectUri $redirect,
        CalendarSync $sync,
        ActivityRecorder $activity,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        // L'état est consommé dès l'arrivée, quelle que soit l'issue : un retour rejoué est refusé.
        $pending = OAuthState::claim($request->string('state')->toString());

        if ($pending === null) {
            return $this->back('danger', __('The connection request has expired or is not valid. Start again from your profile.'));
        }

        if ($request->filled('error')) {
            // L'utilisateur a refusé (access_denied) ou Google a signalé une erreur : rien n'est lié.
            return $this->back('warning', __('Authorisation denied: Google Calendar was not connected.'));
        }

        if (! $settings->isAvailable()) {
            return $this->back('warning', __('Google Calendar linking is not enabled on this instance.'));
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return $this->back('danger', __('The connection request has expired or is not valid. Start again from your profile.'));
        }

        try {
            $tokens = $client->exchangeCode($code, $pending['verifier'], $redirect->resolve());
        } catch (GoogleCalendarException $e) {
            Log::warning('google_calendar_connect_failed', ['user_id' => $user->id, 'status' => $e->httpStatus, 'reason' => $e->reason]);

            return $this->back('danger', __('Google refused the connection. Try again, or tell the administrator if the problem persists.'));
        }

        $storeId = $pending['store_id'];
        $connection = $sync->connect($user->id, $tokens['refresh_token'], $tokens['email'], $pending['name_mode'], $storeId);
        $activity->record('google_calendar.connected', $connection, null, ['name_mode' => $connection->name_mode], $user);

        return $this->back('success', __('Google Calendar connected. Your shifts will appear in the calendar within moments.'));
    }

    private function back(string $variant, string $text): RedirectResponse
    {
        return redirect()->route('google-calendar.profile')->with('google_calendar_flash', ['variant' => $variant, 'text' => $text]);
    }
}
