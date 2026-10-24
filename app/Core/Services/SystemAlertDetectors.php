<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\InstalledBundleRepositoryInterface;
use kintai\Core\Services\BundleRegistry\BundleCatalogService;

/** Détecteurs réels des notifications système : mise à jour, migrations, bundles, sauvegarde, canaux, quota. */
final class SystemAlertDetectors implements SystemAlertSourceInterface
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly GithubUpdateService $github,
        private readonly UpdateService $updates,
        private readonly BundleCatalogService $catalog,
        private readonly InstalledBundleRepositoryInterface $installedBundles,
        private readonly BackupService $backups,
        private readonly NotificationChannelStatus $channels,
        private readonly PlanLimitService $limits,
    ) {}

    public function all(): array
    {
        return [
            'core_update_available'   => fn() => $this->coreUpdate(),
            'migration_pending'       => fn() => $this->pendingMigrations(),
            'bundle_update_available' => fn() => $this->bundleUpdates(),
            'backup_failed'           => fn() => $this->backupFailed(),
            'backup_stale'            => fn() => $this->backupStale(),
            'channel_broken'          => fn() => $this->brokenChannels(),
            'license_quota'           => fn() => $this->licenseQuota(),
        ];
    }

    private function coreUpdate(): ?array
    {
        $info = $this->github->checkLatestRelease();
        if ($info === null || empty($info['has_update'])) {
            return null;
        }
        return ['key' => (string) $info['latest_version'], 'link' => '/admin/update', 'params' => ['version' => (string) $info['latest_version']]];
    }

    private function pendingMigrations(): ?array
    {
        $pending = $this->updates->getPendingMigrations();
        if ($pending === []) {
            return null;
        }
        return ['key' => md5(implode('|', $pending)), 'link' => '/admin/update', 'params' => ['count' => count($pending)]];
    }

    private function bundleUpdates(): ?array
    {
        $installed = [];
        foreach ($this->installedBundles->all() as $row) {
            $installed[(string) $row['slug']] = (string) $row['active_version'];
        }
        if ($installed === []) {
            return null;
        }

        $channel = $this->settings->bundleUpdateChannel();
        $updates = [];
        foreach ($this->catalog->listAvailableBundles() as $entry) {
            $slug   = $entry->bundle->slug;
            $latest = $entry->bundle->versionsForChannel($channel)[0] ?? null;
            if (isset($installed[$slug]) && $latest !== null && version_compare($latest, $installed[$slug], '>')) {
                $updates[$slug] = $latest;
            }
        }
        if ($updates === []) {
            return null;
        }

        // Une seule notification groupée, même si plusieurs bundles ont une mise à jour le même jour.
        ksort($updates);
        $parts = [];
        foreach ($updates as $slug => $version) {
            $parts[] = $slug . '@' . $version;
        }
        return [
            'key'    => md5(implode('|', $parts)),
            'link'   => '/admin/bundles/market',
            'params' => ['count' => count($updates), 'bundles' => implode(', ', array_keys($updates))],
        ];
    }

    /** Dernier échec de la sauvegarde planifiée, mémorisé par BackupJob (effacé au prochain succès). */
    private function backupFailed(): ?array
    {
        $failedAt = $this->settings->get(SystemNotificationService::BACKUP_FAILURE_KEY, '');
        if ($failedAt === '') {
            return null;
        }
        return ['key' => $failedAt, 'link' => '/admin/backup', 'params' => ['date' => $failedAt]];
    }

    private function backupStale(): ?array
    {
        if (!$this->settings->backupAutoEnabled()) {
            return null;
        }
        $list   = $this->backups->list();
        $latest = $list[0]['created_at'] ?? null;
        $limit  = date('Y-m-d H:i:s', strtotime('-' . SystemNotificationService::BACKUP_STALE_DAYS . ' days'));
        if ($latest !== null && $latest >= $limit) {
            return null;
        }
        return ['key' => $latest ?? 'none', 'link' => '/admin/backup', 'params' => ['days' => SystemNotificationService::BACKUP_STALE_DAYS]];
    }

    /** Canal partiellement configuré : clés VAPID/extensions manquantes, FCM activé sans projet ni identifiants, e-mail sans URL publique. */
    private function brokenChannels(): ?array
    {
        $status   = $this->channels->all();
        $problems = [];

        $web = $status['web_push'];
        if (!$web['available']) {
            $problems[] = $web['missing_extensions'] !== [] ? 'web_push_extensions' : 'web_push_keys';
        }
        $mobile = $status['mobile'];
        if ($mobile['enabled'] && !$mobile['available']) {
            $problems[] = 'mobile';
        }
        $email = $status['email'];
        if ($email['configured'] && !$email['has_public_url']) {
            $problems[] = 'email_public_url';
        }

        if ($problems === []) {
            return null;
        }
        return ['key' => implode(',', $problems), 'link' => '/admin/notifications-settings', 'params' => ['count' => count($problems)]];
    }

    private function licenseQuota(): ?array
    {
        $reached   = [];
        $maxStores = $this->limits->maxStores();
        if ($maxStores !== null && $this->limits->currentStoreCount() >= $maxStores) {
            $reached[] = 'stores';
        }
        $maxEmployees = $this->limits->maxEmployees();
        if ($maxEmployees !== null && $this->limits->currentEmployeeCount() >= $maxEmployees) {
            $reached[] = 'employees';
        }
        if ($reached === []) {
            return null;
        }
        return [
            'key'    => implode(',', $reached),
            'link'   => '/admin/license',
            'params' => ['limits' => implode(', ', array_map(fn(string $r) => __('notif_license_quota_' . $r), $reached))],
        ];
    }
}
