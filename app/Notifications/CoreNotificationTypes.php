<?php

namespace App\Notifications;

use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;

/**
 * Types de notification du Core. Les bundles déclarent les leurs.
 */
final class CoreNotificationTypes
{
    public const TEST = 'notification_test';

    public static function registerIn(NotificationTypeRegistry $registry): void
    {
        $registry->register(new NotificationType('shift_assigned', 'New shift assigned', 'planning', 'calendar-days', push: true, mail: true, referenceType: 'shift'));
        $registry->register(new NotificationType('shift_updated', 'Shift updated', 'planning', 'arrow-path', push: true, referenceType: 'shift'));
        // Le shift n'existe plus : la notification désigne le jour (reference_id = AAAAMMJJ).
        $registry->register(new NotificationType('shift_deleted', 'Shift cancelled', 'planning', 'trash', push: true, mail: true, referenceType: 'shift_day'));
        // Alertes système, adressées aux Owners actifs (voir App\System\SystemAlerts). Les mises à jour, bundles et licence viendront en phase 6.
        $registry->register(new NotificationType('migration_pending', 'Database update pending', 'system', 'circle-stack', push: true, mail: true, referenceType: 'system', ownerOnly: true));
        $registry->register(new NotificationType('backup_failed', 'Backup failed', 'system', 'archive-box-x-mark', push: true, mail: true, referenceType: 'system', ownerOnly: true));
        $registry->register(new NotificationType('backup_stale', 'Backup out of date', 'system', 'archive-box-x-mark', push: true, mail: true, referenceType: 'system', ownerOnly: true));
        $registry->register(new NotificationType('channel_broken', 'Notification channel problem', 'system', 'signal-slash', push: true, mail: true, referenceType: 'system', ownerOnly: true));
        $registry->register(new NotificationType(self::TEST, 'Test notification', 'system', 'bell', push: true, mail: true, internal: true));
    }
}
