<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Auth\AuthService;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationChannelStatus;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\VapidKeyStore;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

/**
 * Paramètres des notifications de l'instance (Owner) : délai de conservation des notifications lues, état des canaux,
 * notification de test et régénération des clés du web push.
 */
final class NotificationSettingsController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly AppSettingsService $settings,
        private readonly NotificationChannelStatus $channels,
        private readonly VapidKeyStore $vapid,
        private readonly NotificationService $notifications,
        private readonly AuthService $auth,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** GET /admin/notifications-settings */
    public function show(Request $request): Response
    {
        $sent = (string) $request->query('channels', '');

        return Response::html($this->view->render('system.notification-settings', [
            'title'          => __('notif_admin_title'),
            'retention_days' => $this->settings->notificationsRetentionDays(),
            'retention_min'  => AppSettingsService::NOTIFICATIONS_RETENTION_MIN,
            'retention_max'  => AppSettingsService::NOTIFICATIONS_RETENTION_MAX,
            'status'         => $this->channels->all(),
            // Canaux annoncés par le dernier test, seulement parmi ceux que l'on connaît.
            'test_channels'  => array_values(array_intersect(explode(',', $sent), ['bell', 'web_push', 'mobile', 'email'])),
        ], 'layout.app'));
    }

    /** POST /admin/notifications-settings */
    public function save(Request $request): Response
    {
        $old = $this->settings->notificationsRetentionDays();
        $new = max(
            AppSettingsService::NOTIFICATIONS_RETENTION_MIN,
            min(AppSettingsService::NOTIFICATIONS_RETENTION_MAX, (int) $request->post('notifications_retention_days', (string) $old)),
        );

        $this->settings->setMany(['notifications_retention_days' => (string) $new]);
        $this->auditLogger->logUpdate(
            $request, 'notification_settings.updated', 'system', null,
            ['notifications_retention_days' => (string) $old], ['notifications_retention_days' => (string) $new], [],
        );

        return Response::redirect($this->base() . '/admin/notifications-settings?success=saved');
    }

    /** POST /admin/notifications-settings/test : notification de test à l'Owner, par tous les canaux actifs. */
    public function sendTest(Request $request): Response
    {
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        // Les canaux sont déterminés avant l'envoi : ce sont ceux qui seront réellement tentés.
        $channels = $this->channels->testChannels($userId);

        $this->notifications->notify($userId, NotificationPreferenceService::TEST_TYPE, 'notif_test_body', [], null, '/admin/notifications-settings');

        return Response::redirect($this->base() . '/admin/notifications-settings?success=test&channels=' . implode(',', $channels));
    }

    /** POST /admin/notifications-settings/vapid : nouvelles clés, tous les abonnements web actuels deviennent invalides. */
    public function regenerateVapid(Request $request): Response
    {
        if (!$this->vapid->regenerate()) {
            return Response::redirect($this->base() . '/admin/notifications-settings?error=vapid_failed');
        }
        $this->auditLogger->log($request, 'notification_settings.vapid_regenerated', 'system');

        return Response::redirect($this->base() . '/admin/notifications-settings?success=vapid');
    }
}
