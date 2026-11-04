<?php

namespace App\Notifications;

/**
 * Types de notification du Core. Les bundles déclarent les leurs ; les types système de l'Owner viendront avec
 * la tranche Administration.
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
        $registry->register(new NotificationType(self::TEST, 'Test notification', 'system', 'bell', push: true, mail: true, internal: true));
    }
}
