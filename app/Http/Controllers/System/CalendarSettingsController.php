<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Repositories\CalendarSyncRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleCalendarSettings;
use App\Services\Calendar\GoogleRedirectUri;
use App\Http\Controllers\HasBaseUrl;
use App\View\ViewRenderer;

/**
 * Agendas externes de l'instance (Owner) : identifiants du projet Google Cloud, activation de la liaison Google Calendar
 * et test des identifiants. Chaque instance auto-hébergée crée son propre projet ; le secret client est chiffré en base
 * et n'est jamais renvoyé au navigateur (le champ reste vide, laisser vide conserve le secret enregistré).
 */
final class CalendarSettingsController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly GoogleCalendarSettings $settings,
        private readonly GoogleCalendarClient $client,
        private readonly GoogleRedirectUri $redirect,
        private readonly CalendarSyncRepositoryInterface $repo,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** GET /admin/calendar-settings */
    public function show(Request $request): Response
    {
        $check = (string) $request->query('check', '');

        return response($this->view->render('system.calendar-settings', [
            'title'         => __('gcal_admin_title'),
            'enabled'       => $this->settings->isEnabled(),
            'client_id'     => $this->settings->clientId(),
            'has_secret'    => $this->settings->hasSecret(),
            'available'     => $this->settings->isAvailable(),
            'redirect_uri'  => $this->redirect->resolve($this->base()),
            'connections'   => $this->repo->countActiveConnections(),
            'check'         => in_array($check, ['ok', 'invalid_client', 'unreachable', 'incomplete'], true) ? $check : '',
        ], 'layout.app'));
    }

    /** POST /admin/calendar-settings */
    public function save(Request $request): Response
    {
        $before = ['enabled' => $this->settings->isEnabled(), 'client_id' => $this->settings->clientId()];
        $enabled  = (string) $request->post('google_calendar_enabled', '') === '1';
        $clientId = trim((string) $request->post('google_calendar_client_id', ''));
        $secret   = (string) $request->post('google_calendar_client_secret', '');

        $this->settings->save($enabled, $clientId, $secret);
        // Le secret n'est jamais journalisé : seul le fait qu'il ait changé l'est.
        $this->auditLogger->logUpdate(
            $request, 'calendar_settings.updated', 'system', null,
            $before, ['enabled' => $enabled, 'client_id' => $clientId], ['secret_changed' => trim($secret) !== ''],
        );

        return redirect()->away($this->base() . '/admin/calendar-settings?success=saved');
    }

    /** POST /admin/calendar-settings/test : vérifie l'identifiant et le secret auprès de Google. */
    public function test(Request $request): Response
    {
        $result = $this->settings->clientId() === '' || !$this->settings->hasSecret()
            ? 'incomplete'
            : $this->client->checkCredentials($this->redirect->resolve($this->base()));

        return redirect()->away($this->base() . '/admin/calendar-settings?check=' . $result);
    }
}
