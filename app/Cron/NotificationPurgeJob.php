<?php

declare(strict_types=1);

namespace App\Cron;

use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\WebPushSubscriptionRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AppSettingsService;

/**
 * Purge des notifications : supprime les notifications LUES plus anciennes que le délai de rétention de l'Owner
 * (90 jours par défaut) et les abonnements web push inutilisés depuis plus de 180 jours.
 *
 * Une notification non lue n'est jamais supprimée : elle attend d'être vue, quel que soit son âge.
 */
final class NotificationPurgeJob implements CronJobInterface
{
    /** Un abonnement qu'aucun envoi n'a touché depuis ce délai est une adresse morte jamais signalée expirée. */
    public const SUBSCRIPTION_IDLE_DAYS = 180;

    public function __construct(
        private readonly NotificationRepositoryInterface $notifications,
        private readonly WebPushSubscriptionRepositoryInterface $subscriptions,
        private readonly AppSettingsService $settings,
    ) {}

    public function getName(): string
    {
        return 'notification-purge';
    }

    public function run(Request $request): Response
    {
        $days   = $this->settings->notificationsRetentionDays();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $idle   = date('Y-m-d H:i:s', strtotime('-' . self::SUBSCRIPTION_IDLE_DAYS . ' days'));

        $deleted = $this->notifications->purgeReadBefore($cutoff);
        $expired = $this->subscriptions->purgeUnusedSince($idle);

        return response()->json([
            'ok'                    => true,
            'retention_days'        => $days,
            'cutoff'                => $cutoff,
            'notifications_deleted' => $deleted,
            'subscriptions_deleted' => $expired,
        ]);
    }
}
