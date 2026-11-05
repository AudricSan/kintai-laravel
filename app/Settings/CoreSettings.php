<?php

namespace App\Settings;

use Kintai\Contracts\Settings\SettingDefinition;

/**
 * Réglages du Core. Les bundles (et les tranches suivantes : sauvegardes, alertes système…) déclarent les leurs
 * avec `AppSettings::register()`.
 */
final class CoreSettings
{
    public const SUBTITLE_MAX = 100;

    public const LOGIN_NOTICE_MAX = 300;

    public const MAINTENANCE_MESSAGE_MAX = 500;

    public const MAIL_FROM_NAME_MAX = 100;

    public const API_RATE_LIMIT_MAX = 100000;

    public const ACTIVITY_RETENTION_MAX = 3650;

    public const BACKUP_MAX_KEEP_MAX = 365;

    public const NOTIFICATIONS_RETENTION_MIN = 7;

    public const NOTIFICATIONS_RETENTION_MAX = 3650;

    public static function registerIn(AppSettings $settings): void
    {
        $string = SettingDefinition::TYPE_STRING;
        $int = SettingDefinition::TYPE_INT;
        $bool = SettingDefinition::TYPE_BOOL;

        $settings->register(
            // Organisation
            new SettingDefinition('organization.subtitle', $string, ''),
            new SettingDefinition('organization.login_notice', $string, ''),
            new SettingDefinition('organization.support_email', $string, ''),
            new SettingDefinition('organization.public_url', $string, ''),

            // Apparence : couleurs claires (défaut de la palette), sombres facultatives, mode de calcul des sombres.
            new SettingDefinition('theme.dark_mode', $string, 'auto'),

            // Mails : vides = `config/mail.php`
            new SettingDefinition('mail.from_address', $string, ''),
            new SettingDefinition('mail.from_name', $string, ''),

            // Instance
            new SettingDefinition('instance.locale', $string, 'en', config: 'app.locale'),
            new SettingDefinition('instance.timezone', $string, 'UTC', config: 'kintai.timezone'),

            // Maintenance
            new SettingDefinition('maintenance.enabled', $bool, false),
            new SettingDefinition('maintenance.message', $string, ''),

            // Limites et rétentions
            new SettingDefinition('api.rate_limit', $int, 300, 0, self::API_RATE_LIMIT_MAX, config: 'kintai.api.rate_limit'),
            new SettingDefinition('activity.retention_days', $int, 180, 0, self::ACTIVITY_RETENTION_MAX, config: 'kintai.activity.retention_days'),
            new SettingDefinition('notifications.retention_days', $int, 90, self::NOTIFICATIONS_RETENTION_MIN, self::NOTIFICATIONS_RETENTION_MAX, config: 'kintai.notifications.retention_days'),

            // Sauvegardes : `last_success_at` (ISO 8601) et `last_failure` sont écrits par la sauvegarde automatique.
            new SettingDefinition('backup.auto_enabled', $bool, true),
            new SettingDefinition('backup.max_keep', $int, 14, 0, self::BACKUP_MAX_KEEP_MAX),
            new SettingDefinition('backup.last_success_at', $string, ''),
            new SettingDefinition('backup.last_failure', $string, ''),

            // Alertes système : état mémorisé (type => clé de l'alerte, JSON) et date de la dernière passe (horodatage Unix).
            new SettingDefinition('system.alerts_state', $string, ''),
            new SettingDefinition('system.alerts_last_run', $int, 0, 0),

            // Documentation intégrée : date (ISO 8601) de la dernière synchronisation réussie du wiki.
            new SettingDefinition('docs.last_sync_at', $string, ''),

            // Bundles activés par l'Owner : liste JSON de slugs (aucun à l'installation).
            new SettingDefinition('bundles.enabled', $string, '[]'),
            // Bundles déjà initialisés (droits par défaut accordés aux rôles à leur première activation) : liste JSON de slugs.
            new SettingDefinition('bundles.initialized', $string, '[]'),

            // Web Push : la clé privée est chiffrée en base.
            new SettingDefinition('webpush.public_key', $string, '', config: 'kintai.webpush.public_key'),
            new SettingDefinition('webpush.private_key', $string, '', encrypted: true, config: 'kintai.webpush.private_key'),
        );

        foreach (ThemeColorPalette::DEFAULTS as $group => $default) {
            $settings->register(
                new SettingDefinition('theme.'.$group, $string, $default),
                new SettingDefinition('theme.'.$group.'_dark', $string, ''),
            );
        }
    }
}
