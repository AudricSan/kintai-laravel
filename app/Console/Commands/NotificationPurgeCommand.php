<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\NotificationRepositoryInterface;
use App\Models\WebPushSubscription;
use App\Services\AppSettingsService;
use Illuminate\Console\Command;

/**
 * Purge des notifications : supprime les notifications LUES plus anciennes que le délai de rétention de l'Owner
 * (90 jours par défaut) et les abonnements web push inutilisés depuis plus de 180 jours.
 *
 * Une notification non lue n'est jamais supprimée : elle attend d'être vue, quel que soit son âge.
 */
final class NotificationPurgeCommand extends Command
{
    /** Un abonnement qu'aucun envoi n'a touché depuis ce délai est une adresse morte jamais signalée expirée. */
    public const SUBSCRIPTION_IDLE_DAYS = 180;

    protected $signature = 'kintai:notification-purge';

    protected $description = 'Purge des notifications lues';

    public function handle(NotificationRepositoryInterface $notifications, AppSettingsService $settings): int
    {
        $days   = $settings->notificationsRetentionDays();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $idle   = date('Y-m-d H:i:s', strtotime('-' . self::SUBSCRIPTION_IDLE_DAYS . ' days'));

        $this->line(json_encode([
            'ok'                    => true,
            'retention_days'        => $days,
            'cutoff'                => $cutoff,
            'notifications_deleted' => $notifications->purgeReadBefore($cutoff),
            'subscriptions_deleted' => WebPushSubscription::purgeUnusedSince($idle),
        ]));

        return self::SUCCESS;
    }
}
