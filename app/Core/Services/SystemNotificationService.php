<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Auth\OwnerGuard;

/**
 * Notifications « système » adressées aux Owners uniquement : mise à jour du Core ou d'un bundle, migrations en
 * attente, sauvegarde en échec ou trop ancienne, canal de notification mal configuré, quota de licence atteint.
 *
 * Chaque détecteur renvoie une alerte (`key` = ce qui la distingue : version, jeu de migrations…) ou null quand tout va
 * bien. Une alerte n'est notifiée qu'UNE fois par `key` (anti-doublon mémorisé dans app_settings) ; quand la cause
 * disparaît (mise à jour faite, migrations appliquées), la notification est marquée lue d'elle-même.
 *
 * Deux déclencheurs : la tâche planifiée `system-notifications` (tout, réseau compris) et le repli à la connexion d'un
 * Owner (instances sans cron : détecteurs locaux seulement, jamais d'appel réseau pendant une page).
 */
final class SystemNotificationService
{
    public const REFERENCE_TYPE = 'system';

    /** Réglage où BackupJob mémorise la date du dernier échec de la sauvegarde planifiée (effacé au succès suivant). */
    public const BACKUP_FAILURE_KEY = 'backup_last_failure';

    /** Un identifiant stable par type (reference_id), pour le « lu » automatique et la résolution. */
    public const REFERENCE_IDS = [
        'core_update_available'   => 1,
        'migration_pending'       => 2,
        'bundle_update_available' => 3,
        'backup_failed'           => 4,
        'backup_stale'            => 5,
        'channel_broken'          => 6,
        'license_quota'           => 7,
    ];

    /** Types qui demandent un appel réseau (GitHub, registries) : jamais lancés depuis une page. */
    private const NETWORK_TYPES = ['core_update_available', 'bundle_update_available'];

    /** Une sauvegarde automatique plus vieille que ça est considérée comme manquante. */
    public const BACKUP_STALE_DAYS = 2;

    private const STATE_KEY    = 'system_notifications_state';
    private const LAST_RUN_KEY = 'system_notifications_last_run';

    public function __construct(
        private readonly OwnerGuard $owners,
        private readonly NotificationService $notifications,
        private readonly AppSettingsService $settings,
        private readonly SystemAlertSourceInterface $detectors,
    ) {}

    /**
     * Repli à la connexion d'un Owner : au plus une passe par $minIntervalSeconds, détecteurs locaux seulement.
     *
     * @return list<string> types notifiés
     */
    public function runIfDue(int $minIntervalSeconds = 3600): array
    {
        $last = (int) $this->settings->get(self::LAST_RUN_KEY, '0');
        if ($last > 0 && time() - $last < $minIntervalSeconds) {
            return [];
        }
        $this->settings->setMany([self::LAST_RUN_KEY => (string) time()]);
        return $this->run(false);
    }

    /**
     * @return list<string> types notifiés pendant cette passe
     */
    public function run(bool $withNetwork = true): array
    {
        $ownerIds = $this->owners->activeOwnerIds();
        if ($ownerIds === []) {
            return [];
        }

        $state = $this->loadState();
        $sent  = [];

        foreach ($this->detectors->all() as $type => $detect) {
            if (!$withNetwork && in_array($type, self::NETWORK_TYPES, true)) {
                continue;
            }
            try {
                $alert = $detect();
            } catch (\Throwable $e) {
                // Un détecteur en panne (réseau, registry) ne doit ni bloquer les autres ni effacer l'état connu.
                Log::warning('system_notification_detector_failed', ['type' => $type, 'error' => $e->getMessage()]);
                continue;
            }

            if ($alert === null) {
                if (isset($state[$type])) {
                    // Cause disparue : la notification n'a plus d'objet, elle se retire de la cloche.
                    foreach ($ownerIds as $ownerId) {
                        $this->notifications->markReadFor($ownerId, self::REFERENCE_TYPE, self::REFERENCE_IDS[$type]);
                    }
                    unset($state[$type]);
                }
                continue;
            }

            if (($state[$type] ?? null) === $alert['key']) {
                continue;
            }

            $this->notifications->notifyMany(
                $ownerIds,
                $type,
                'notif_' . $type . '_body',
                $alert['params'] ?? [],
                self::REFERENCE_IDS[$type],
                $alert['link']
            );
            $state[$type] = $alert['key'];
            $sent[]       = $type;
        }

        $this->saveState($state);
        return $sent;
    }

    /** @return array<string, string> */
    private function loadState(): array
    {
        $decoded = json_decode($this->settings->get(self::STATE_KEY, '{}'), true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private function saveState(array $state): void
    {
        $this->settings->setMany([self::STATE_KEY => json_encode($state, JSON_UNESCAPED_SLASHES)]);
    }
}
