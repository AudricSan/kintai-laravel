<?php

namespace App\Actions\Notifications;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Supprime les notifications LUES plus anciennes que la rétention ; une notification non lue n'est jamais supprimée.
 */
class PurgeNotifications
{
    public const RETENTION_MIN = 7;

    public const RETENTION_MAX = 3650;

    public const RETENTION_DEFAULT = 90;

    /**
     * Rétention configurée (`kintai.notifications.retention_days`), ramenée entre 7 et 3650 jours.
     */
    public static function retentionDays(): int
    {
        $days = (int) config('kintai.notifications.retention_days', self::RETENTION_DEFAULT);

        return max(self::RETENTION_MIN, min(self::RETENTION_MAX, $days > 0 ? $days : self::RETENTION_DEFAULT));
    }

    /**
     * @return int Nombre de notifications supprimées (une notification lue est datée de sa création, comme dans l'ancienne version).
     */
    public function handle(): int
    {
        return DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(self::retentionDays()))
            ->delete();
    }
}
