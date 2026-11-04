<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Notifications\CoreNotificationTypes;
use App\Notifications\KintaiNotification;

/**
 * Notification de test que l'utilisateur s'envoie : elle passe par tous ses canaux actifs (cloche, e-mail).
 */
class SendTestNotification
{
    public function handle(User $user): void
    {
        $user->notify(KintaiNotification::make(
            CoreNotificationTypes::TEST,
            'This is a test notification sent from your notification settings. If you can read it, this channel works.',
            [],
            route('notifications.index', [], false),
        ));
    }
}
