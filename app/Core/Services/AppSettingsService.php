<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;

final class AppSettingsService
{
    private array $cache = [];

    public function __construct(private readonly AppSettingsRepositoryInterface $repo)
    {
        $this->cache = $repo->all();
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->cache[$key] ?? $default;
    }

    public function setMany(array $settings): void
    {
        $this->repo->setMany($settings);
        foreach ($settings as $k => $v) {
            $this->cache[(string) $k] = (string) $v;
        }
    }

    // ── Accesseurs typés ──────────────────────────────────────────────────────

    /** Nom de l'entreprise affiché sous "Kintai" dans la sidebar et la page de connexion. */
    public function subtitle(): string
    {
        return $this->get('app_subtitle');
    }

    /** Message affiché sur la page de connexion (maintenance, annonces, etc.). */
    public function loginNotice(): string
    {
        return $this->get('app_login_notice');
    }

    /** E-mail de support affiché aux utilisateurs (pages d'erreur, footer). */
    public function supportEmail(): string
    {
        return $this->get('app_support_email');
    }

    /**
     * Couleur claire d'un groupe personnalisable du thème (primary, accent,
     * table_highlight, success, warning, danger, info). Retombe sur la valeur par
     * défaut de la palette de la mascotte Foxy si non réglée ou invalide.
     */
    public function themeColor(string $group): string
    {
        $default = ThemeColorPalette::DEFAULTS[$group] ?? '';
        $stored = $this->get("app_{$group}_color", $default);
        return ThemeColorPalette::isValidHex($stored) ? $stored : $default;
    }

    /** Couleur sombre réglée manuellement pour ce groupe ('' si non réglée : mode auto). */
    public function themeColorDark(string $group): string
    {
        return $this->get("app_{$group}_color_dark", '');
    }

    /** Mode de calcul des couleurs sombres : 'auto' (calculées par HSL) ou 'manual'. */
    public function themeDarkMode(): string
    {
        return $this->get('app_theme_dark_mode', ThemeColorPalette::DEFAULT_DARK_MODE) === 'manual' ? 'manual' : 'auto';
    }

    /** Variables CSS (--light-xxx, --dark-xxx) de tous les groupes personnalisés à injecter en style inline sur <html>. */
    public function themeColorStyle(): string
    {
        $light = [];
        $dark = [];
        foreach (ThemeColorPalette::DEFAULTS as $group => $default) {
            $light[$group] = $this->themeColor($group);
            $dark[$group] = $this->themeColorDark($group);
        }
        return ThemeColorPalette::toInlineStyle($light, $dark, $this->themeDarkMode());
    }

    // ── Backup ─────────────────────────────────────────────────────────────────

    /** Nombre maximal de sauvegardes à conserver (0 = illimité). */
    public function backupMaxKeep(): int
    {
        return max(0, (int) $this->get('backup_max_keep', '0'));
    }

    /** Sauvegarde automatique activée (cron). */
    public function backupAutoEnabled(): bool
    {
        return $this->get('backup_auto_enabled', '1') === '1';
    }

    // ── Mises à jour ───────────────────────────────────────────────────────────

    /** Canal de mise à jour suivi : release (défaut), beta, ou alpha. */
    public function updateChannel(): string
    {
        $channel = $this->get('update_channel', 'release');
        return in_array($channel, ['alpha', 'beta', 'release'], true) ? $channel : 'release';
    }

    /**
     * Canal de mise à jour des bundles : release (défaut), beta, ou alpha —
     * réglage global, s'applique à tous les bundles installés (pas un canal
     * par bundle). Indépendant de updateChannel() (celui du Core lui-même).
     */
    public function bundleUpdateChannel(): string
    {
        $channel = $this->get('bundle_update_channel', 'release');
        return in_array($channel, ['alpha', 'beta', 'release'], true) ? $channel : 'release';
    }

    // ── Mascotte ───────────────────────────────────────────────────────────────

    /** Mode d'affichage de la mascotte : mix (défaut, tirée au sort par requête), kitsune, ou tanuki. */
    public function mascotMode(): string
    {
        $mode = $this->get('app_mascot_mode', 'mix');
        return in_array($mode, ['mix', 'kitsune', 'tanuki'], true) ? $mode : 'mix';
    }

    // ── Expéditeur des e-mails ────────────────────────────────────────────────

    /** Adresse d'expédition choisie par l'Owner pour tous les e-mails (vide = celle de config/mail.php). */
    public function mailFromAddress(): string
    {
        $address = trim($this->get('mail_from_address'));

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '';
    }

    /** Nom d'expéditeur choisi par l'Owner (vide = celui de config/mail.php). */
    public function mailFromName(): string
    {
        return trim($this->get('mail_from_name'));
    }

    /**
     * Configuration d'envoi dont l'expéditeur est celui réglé par l'Owner : un seul endroit pour tous les e-mails
     * (rapports, notifications, réinitialisation de mot de passe), sans toucher au fichier de configuration.
     *
     * @param array<string, mixed> $config contenu de config/mail.php
     * @return array<string, mixed>
     */
    public function mailConfig(array $config): array
    {
        $address = $this->mailFromAddress();
        $name    = $this->mailFromName();
        if ($address !== '') {
            $config['from']['address'] = $address;
        }
        if ($name !== '') {
            $config['from']['name'] = $name;
        }

        return $config;
    }

    // ── Mode maintenance ──────────────────────────────────────────────────────

    /** Mode maintenance activé : seul l'Owner peut accéder aux pages web. */
    public function maintenanceModeEnabled(): bool
    {
        return $this->get('maintenance_mode_enabled', '0') === '1';
    }

    /** Message affiché sur la page de maintenance (vide = message générique par défaut). */
    public function maintenanceMessage(): string
    {
        return $this->get('maintenance_message');
    }

    // ── Journal d'activité ────────────────────────────────────────────────────

    /** Journal d'accès HTTP automatique (canal "access") activé pour chaque requête. */
    public function accessLogEnabled(): bool
    {
        return $this->get('access_log_enabled', '1') === '1';
    }

    /** Nombre de jours de rétention des entrées du journal d'activité (0 = illimité, pas de purge auto). */
    public function logRetentionDays(): int
    {
        return max(0, (int) $this->get('log_retention_days', '180'));
    }

    /** Rétention minimale des notifications lues : en dessous, une notification pourrait disparaître avant d'avoir été relue. */
    public const NOTIFICATIONS_RETENTION_MIN = 7;
    public const NOTIFICATIONS_RETENTION_MAX = 3650;

    /** Jours pendant lesquels une notification LUE est conservée avant la purge (90 par défaut, 7 au minimum). */
    public function notificationsRetentionDays(): int
    {
        $days = (int) $this->get('notifications_retention_days', '90');

        return max(self::NOTIFICATIONS_RETENTION_MIN, min(self::NOTIFICATIONS_RETENTION_MAX, $days > 0 ? $days : 90));
    }

    /** Requêtes API autorisées par minute et par jeton (ApiRateLimitMiddleware) ; 0 = pas de limite. */
    public function apiRateLimitPerMinute(): int
    {
        $default = (string) \kintai\Core\Middleware\ApiRateLimitMiddleware::DEFAULT_PER_MINUTE;
        return max(0, (int) $this->get(\kintai\Core\Middleware\ApiRateLimitMiddleware::SETTING_KEY, $default));
    }
}
