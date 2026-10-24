<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

use kintai\Core\Request;
use kintai\Core\Services\NotificationChannelStatus;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationService;

/**
 * Notifications : état des canaux de l'instance (cloche, web push, mobile, e-mail) et envoi d'une notification de test
 * à l'Owner par tous les canaux disponibles. Rien à saisir : les clés VAPID sont créées automatiquement, FCM et le
 * choix des types se règlent dans les réglages des notifications.
 */
final class NotificationsStep implements SetupStepInterface
{
    public function __construct(
        private readonly NotificationChannelStatus $channels,
        private readonly NotificationService $notifications,
    ) {}

    public function id(): string
    {
        return 'notifications';
    }

    public function data(Request $request): array
    {
        return ['status' => $this->channels->all()];
    }

    /** « Envoyer une notification de test » : l'étape est faite dans les deux cas, l'envoi est un plus. */
    public function handle(Request $request, int $userId): array
    {
        if ($request->post('send_test', '') === '1') {
            $this->notifications->notify($userId, NotificationPreferenceService::TEST_TYPE, 'notif_test_body', [], null, '/admin/notifications-settings');
        }
        return [];
    }

    public function summary(): ?string
    {
        $status = $this->channels->all();
        $on = array_keys(array_filter([
            'web push' => $status['web_push']['available'],
            'e-mail'   => $status['email']['available'],
            'mobile'   => $status['mobile']['available'],
        ]));
        return $on === [] ? null : implode(', ', $on);
    }
}
