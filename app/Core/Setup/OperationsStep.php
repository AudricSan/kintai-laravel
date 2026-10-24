<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;

/**
 * Exploitation : sauvegarde automatique (activée, nombre à conserver), canal de mises à jour du Core et des bundles, et
 * rappel du planificateur à mettre en place (commande ou URL prêtes à copier). Mêmes réglages que les pages Sauvegardes
 * et Mises à jour.
 */
final class OperationsStep implements SetupStepInterface
{
    private const CHANNELS = ['release', 'beta', 'alpha'];

    public function __construct(private readonly AppSettingsService $settings) {}

    public function id(): string
    {
        return 'operations';
    }

    public function data(Request $request): array
    {
        $base = ($request->isSecure() ? 'https' : 'http') . '://' . (string) $request->server('HTTP_HOST', 'votre-site') . rtrim(base_url(), '/');
        return [
            'backup_auto'   => $this->settings->backupAutoEnabled(),
            'backup_keep'   => $this->settings->backupMaxKeep(),
            'update_channel' => $this->settings->updateChannel(),
            'channels'      => self::CHANNELS,
            'cron_command'  => 'php ' . BASE_PATH . '/scripts/cron.php',
            'cron_url'      => $base . '/cron/run/backup',
            'cron_token_command' => 'php ' . BASE_PATH . '/scripts/create-cron-token.php --user-id=1 --job=backup',
        ];
    }

    public function handle(Request $request, int $userId): array
    {
        $channel = (string) $request->post('update_channel', 'release');
        $keep    = (int) $request->post('backup_keep', '0');
        if (!in_array($channel, self::CHANNELS, true) || $keep < 0 || $keep > 365) {
            return ['setup_ops_invalid'];
        }
        $this->settings->setMany([
            'backup_auto_enabled'   => $request->post('backup_auto', '') === '1' ? '1' : '0',
            'backup_max_keep'       => (string) $keep,
            'update_channel'        => $channel,
            'bundle_update_channel' => $channel,
        ]);
        return [];
    }

    public function summary(): ?string
    {
        return ($this->settings->backupAutoEnabled() ? 'backup ✓' : 'backup ✗') . ' · ' . $this->settings->updateChannel();
    }
}
