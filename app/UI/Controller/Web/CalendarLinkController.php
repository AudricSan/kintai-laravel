<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\CalendarSyncRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\SessionFlash;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Calendar\GoogleCalendarClient;
use kintai\Core\Services\Calendar\GoogleCalendarException;
use kintai\Core\Services\Calendar\GoogleCalendarSettings;
use kintai\Core\Services\CalendarSyncService;
use kintai\Core\Services\Calendar\GoogleRedirectUri;
use kintai\Core\Services\Log;

/**
 * Liaison de l'agenda Google de l'utilisateur connecté (onglet « iCal » du profil). Chacun ne lie et ne délie que son
 * propre agenda : aucun identifiant d'utilisateur ne vient de la requête.
 *
 * Flux OAuth : code d'autorisation + PKCE. Le `state` (anti-CSRF) et le vérificateur PKCE restent côté serveur, dans
 * la session de la personne qui a lancé la liaison, valables 10 minutes et à usage unique : un retour de Google
 * ouvert depuis une autre session (ou rejoué) est refusé.
 */
final class CalendarLinkController
{
    use HasBaseUrl;

    private const SESSION_KEY = '_kintai_gcal_oauth';
    private const STATE_TTL   = 600;

    public function __construct(
        private readonly AuthService $auth,
        private readonly GoogleCalendarSettings $settings,
        private readonly GoogleCalendarClient $client,
        private readonly CalendarSyncService $sync,
        private readonly CalendarSyncRepositoryInterface $repo,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly GoogleRedirectUri $redirect,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** POST /profile/calendar/google/connect : envoie la personne chez Google pour autoriser Kintai. */
    public function connect(Request $request): Response
    {
        if (!$this->settings->isAvailable()) {
            SessionFlash::put('warning', __('gcal_not_available'));
            return $this->back();
        }

        [$mode, $storeId] = $this->nameChoice($request);
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state    = bin2hex(random_bytes(24));
        $_SESSION[self::SESSION_KEY] = [
            'state' => $state, 'verifier' => $verifier, 'at' => time(), 'mode' => $mode, 'store_id' => $storeId,
        ];

        $user = $this->auth->user() ?? [];
        return Response::redirect($this->client->authorizationUrl(
            $this->redirectUri($request),
            $state,
            GoogleCalendarClient::codeChallenge($verifier),
            (string) ($user['email'] ?? ''),
        ));
    }

    /** GET /profile/calendar/google/callback : retour de Google (autorisation donnée, refusée ou expirée). */
    public function callback(Request $request): Response
    {
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        $userId = (int) ($this->auth->user()['id'] ?? 0);

        if (!is_array($pending) || (time() - (int) ($pending['at'] ?? 0)) > self::STATE_TTL
            || !hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state', ''))) {
            SessionFlash::put('danger', __('gcal_state_invalid'));
            return $this->back();
        }
        if ((string) $request->query('error', '') !== '') {
            // L'utilisateur a refusé (access_denied) ou Google a signalé une erreur : rien n'est lié.
            SessionFlash::put('warning', __('gcal_denied'));
            return $this->back();
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            SessionFlash::put('danger', __('gcal_state_invalid'));
            return $this->back();
        }

        try {
            $tokens = $this->client->exchangeCode($code, (string) $pending['verifier'], $this->redirectUri($request));
        } catch (GoogleCalendarException $e) {
            Log::warning('google_calendar_connect_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            SessionFlash::put('danger', __('gcal_connect_failed'));
            return $this->back();
        }

        $this->sync->connect($userId, $tokens['refresh_token'], $tokens['email'], [
            'calendar_name_mode' => $pending['mode'] ?? 'instance',
            'store_id'           => (int) ($pending['store_id'] ?? 0),
        ]);
        $this->auditLogger->log($request, 'calendar.connected', 'user', $userId, ['provider' => 'google']);
        SessionFlash::put('success', __('gcal_connected'));
        return $this->back();
    }

    /** POST /profile/calendar/google/disconnect */
    public function disconnect(Request $request): Response
    {
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $this->sync->disconnect($userId, (string) $request->post('delete_events', '') === '1');
        $this->auditLogger->log($request, 'calendar.disconnected', 'user', $userId, ['provider' => 'google']);
        SessionFlash::put('success', __('gcal_disconnected'));
        return $this->back();
    }

    /** POST /profile/calendar/google/options : nom de l'agenda, renommé chez Google tout de suite (sinon à la prochaine synchro). */
    public function options(Request $request): Response
    {
        $userId     = (int) ($this->auth->user()['id'] ?? 0);
        $connection = $this->repo->findConnectionByUser($userId);
        if ($connection === null) {
            return $this->back();
        }

        [$mode, $storeId] = $this->nameChoice($request);
        $options = json_decode((string) ($connection['options'] ?? ''), true);
        $options = is_array($options) ? $options : [];
        $options['calendar_name_mode'] = $mode;
        $options['store_id']           = $storeId;
        $this->repo->updateConnection((int) $connection['id'], ['options' => json_encode($options)]);
        $connection = $this->repo->findConnection((int) $connection['id']) ?? $connection;
        try {
            $this->sync->ensureCalendarFor($connection);
        } catch (GoogleCalendarException) {
            // Google injoignable : le renommage sera repris au prochain événement synchronisé.
        }

        SessionFlash::put('success', __('gcal_options_saved'));
        return $this->back();
    }

    /** POST /profile/calendar/google/resync : remet tout en file (utile après une panne). */
    public function resync(Request $request): Response
    {
        $userId     = (int) ($this->auth->user()['id'] ?? 0);
        $connection = $this->repo->findConnectionByUser($userId);
        if ($connection !== null && ($connection['status'] ?? '') === 'active') {
            $this->sync->reconcile($connection);
            SessionFlash::put('success', __('gcal_resync_queued'));
        }
        return $this->back();
    }

    /**
     * Choix du nom de l'agenda : celui de l'entreprise, ou celui d'un magasin où la personne travaille.
     *
     * @return array{string, int}
     */
    private function nameChoice(Request $request): array
    {
        $mode    = (string) $request->post('calendar_name_mode', 'instance') === 'store' ? 'store' : 'instance';
        $storeId = (int) $request->post('store_id', '0');
        if ($mode === 'store') {
            $userId = (int) ($this->auth->user()['id'] ?? 0);
            // Seul un magasin de la personne est accepté : le nom d'un autre magasin n'a pas à fuiter dans son agenda.
            if ($storeId <= 0 || $this->storeUsers->findMembership($storeId, $userId) === null) {
                return ['instance', 0];
            }
        }
        return [$mode, $mode === 'store' ? $storeId : 0];
    }

    private function redirectUri(Request $request): string
    {
        return $this->redirect->resolve($this->base());
    }

    private function back(): Response
    {
        return Response::redirect($this->base() . '/profile?tab=ical');
    }
}
