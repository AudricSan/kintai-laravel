<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Middleware\ApiRateLimitMiddleware;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\ThemeColorPalette;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class OwnerSettingsController
{
    use HasBaseUrl;
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly AppSettingsService $settings,
        private readonly AuditLogger $auditLogger,
        private readonly PublicUrlResolver $publicUrl,
        // Langue de l'instance (APP_LOCALE) ; le conteneur les injecte toujours.
        private readonly ?InstanceLocale $instanceLocale = null,
        private readonly ?LanguageRepositoryInterface $languages = null,
    ) {}

    /** GET /admin/owner-settings */
    public function show(Request $request): Response
    {
        $themeColors = [];
        $themeColorsDark = [];
        foreach (ThemeColorPalette::DEFAULTS as $group => $default) {
            $themeColors[$group] = $this->settings->themeColor($group);
            // Pré-remplit le sélecteur "variante sombre" (affiché en mode manuel) avec la
            // teinte auto-calculée tant qu'aucune n'a été réglée à la main : si l'Owner
            // bascule en manuel et enregistre sans y toucher, la couleur claire brute ne se
            // retrouve pas enregistrée telle quelle comme variante sombre (illisible sur
            // fond sombre pour la plupart des couleurs).
            $storedDark = $this->settings->themeColorDark($group);
            $themeColorsDark[$group] = $storedDark !== '' ? $storedDark : ThemeColorPalette::autoDarkBase($themeColors[$group]);
        }

        return Response::html($this->view->render('system.owner-settings', [
            'title'    => __('owner_settings'),
            'settings' => [
                'app_subtitle'      => $this->settings->subtitle(),
                'app_login_notice'  => $this->settings->loginNotice(),
                'app_support_email' => $this->settings->supportEmail(),
                'mail_from_address' => $this->settings->mailFromAddress(),
                'mail_from_name'    => $this->settings->mailFromName(),
                'maintenance_mode_enabled' => $this->settings->maintenanceModeEnabled() ? '1' : '0',
                'maintenance_message'      => $this->settings->maintenanceMessage(),
                'access_log_enabled'       => $this->settings->accessLogEnabled() ? '1' : '0',
                'log_retention_days'       => $this->settings->logRetentionDays(),
                'api_rate_limit_per_minute' => $this->settings->apiRateLimitPerMinute(),
                'app_mascot_mode'          => $this->settings->mascotMode(),
                'app_public_url'           => $this->settings->get(PublicUrlResolver::SETTING_KEY),
            ],
            'public_url_forced_by_env' => $this->publicUrl->isForcedByEnvironment(),
            'public_url_effective'     => $this->publicUrl->resolve(),
            // Simple suggestion affichée à l'Owner (jamais enregistrée sans son action) : l'adresse par laquelle il
            // consulte cette page. Un Owner authentifié n'a aucune raison de forger son propre en-tête Host.
            'public_url_suggestion'    => PublicUrlResolver::normalize(
                ($request->isSecure() ? 'https' : 'http') . '://' . (string) $request->server('HTTP_HOST', '') . $this->base()
            ),
            'theme_colors'      => $themeColors,
            'theme_colors_dark' => $themeColorsDark,
            'theme_dark_mode'   => $this->settings->themeDarkMode(),
            'instance_locale'   => $this->instanceLocale?->code(),
            'instance_languages' => $this->languages?->findAllActive() ?? [],
            'success' => isset($_GET['success']),
            'error'   => in_array($request->query('error'), ['public_url_invalid', 'instance_locale_not_saved', 'mail_from_invalid'], true) ? $request->query('error') : null,
        ], 'layout.app'));
    }

    /** POST /admin/owner-settings */
    public function save(Request $request): Response
    {
        // Champ absent de la requête : réglage actuel conservé (audit du 13/10/2026, M12). Avant, une requête
        // partielle effaçait les textes, désactivait le journal d'accès et coupait le mode maintenance.
        $subtitle     = substr(trim((string) $request->post('app_subtitle', $this->settings->subtitle())), 0, 100);
        $loginNotice  = substr(trim((string) $request->post('app_login_notice', $this->settings->loginNotice())), 0, 300);
        $supportEmail = trim((string) $request->post('app_support_email', $this->settings->supportEmail()));

        if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $supportEmail = '';
        }

        // Expéditeur des e-mails : une adresse invalide est refusée (l'ancienne est conservée) plutôt qu'ignorée en silence.
        // Vide = on retombe sur config/mail.php.
        $mailFromAddress = trim((string) $request->post('mail_from_address', $this->settings->mailFromAddress()));
        if ($mailFromAddress !== '' && !filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL)) {
            return Response::redirect($this->base() . '/admin/owner-settings?error=mail_from_invalid');
        }
        $mailFromName = substr(trim((string) $request->post('mail_from_name', $this->settings->mailFromName())), 0, 100);

        $darkMode = $request->post('app_theme_dark_mode', $this->settings->themeDarkMode()) === 'manual' ? 'manual' : 'auto';

        // Couleurs personnalisables du thème (voir ThemeColorPalette) : une valeur claire
        // invalide retombe sur la valeur actuellement enregistrée (pas sur le défaut Foxy,
        // pour ne pas silencieusement réinitialiser un autre réglage en cas de champ vide).
        // La couleur sombre manuelle, elle, retombe sur '' (= mode auto pour ce groupe) si
        // invalide : elle n'a de sens que lorsque $darkMode === 'manual'.
        $oldThemeData = [];
        $newThemeData = [];
        foreach (ThemeColorPalette::DEFAULTS as $group => $default) {
            $oldThemeData["app_{$group}_color"] = $this->settings->themeColor($group);
            $oldThemeData["app_{$group}_color_dark"] = $this->settings->themeColorDark($group);

            $light = strtolower(trim((string) $request->post("app_{$group}_color", '')));
            $newThemeData["app_{$group}_color"] = ThemeColorPalette::isValidHex($light)
                ? $light
                : $oldThemeData["app_{$group}_color"];

            $dark = strtolower(trim((string) $request->post("app_{$group}_color_dark", $oldThemeData["app_{$group}_color_dark"])));
            $newThemeData["app_{$group}_color_dark"] = ThemeColorPalette::isValidHex($dark) ? $dark : '';
        }
        $oldThemeData['app_theme_dark_mode'] = $this->settings->themeDarkMode();
        $newThemeData['app_theme_dark_mode'] = $darkMode;

        $maintenanceModeEnabled = (string) $request->postFlag('maintenance_mode_enabled', $this->settings->maintenanceModeEnabled());
        $maintenanceMessage     = substr(trim((string) $request->post('maintenance_message', $this->settings->maintenanceMessage())), 0, 500);

        $accessLogEnabled = (string) $request->postFlag('access_log_enabled', $this->settings->accessLogEnabled());
        $logRetentionDays = max(0, min(3650, (int) $request->post('log_retention_days', (string) $this->settings->logRetentionDays())));
        // 0 = pas de limite ; champ absent = valeur actuelle conservée.
        $apiRateLimit = max(0, min(ApiRateLimitMiddleware::MAX_PER_MINUTE, (int) $request->post('api_rate_limit_per_minute', (string) $this->settings->apiRateLimitPerMinute())));

        $mascotMode = (string) $request->post('app_mascot_mode', $this->settings->mascotMode());
        $mascotMode = in_array($mascotMode, ['mix', 'kitsune', 'tanuki'], true) ? $mascotMode : 'mix';

        // URL publique : une valeur invalide est refusée (l'ancienne est conservée) plutôt qu'enregistrée telle quelle.
        $publicUrlInput = trim((string) $request->post('app_public_url', $this->settings->get(PublicUrlResolver::SETTING_KEY)));
        $publicUrl      = $publicUrlInput === '' ? '' : PublicUrlResolver::normalize($publicUrlInput);
        if ($publicUrl === null) {
            return Response::redirect($this->base() . '/admin/owner-settings?error=public_url_invalid');
        }

        $oldData = array_merge([
            'app_subtitle'      => $this->settings->subtitle(),
            'app_login_notice'  => $this->settings->loginNotice(),
            'app_support_email' => $this->settings->supportEmail(),
            'mail_from_address' => $this->settings->mailFromAddress(),
            'mail_from_name'    => $this->settings->mailFromName(),
            'maintenance_mode_enabled' => $this->settings->maintenanceModeEnabled() ? '1' : '0',
            'maintenance_message'      => $this->settings->maintenanceMessage(),
            'access_log_enabled'      => $this->settings->accessLogEnabled() ? '1' : '0',
            'log_retention_days'      => (string) $this->settings->logRetentionDays(),
            ApiRateLimitMiddleware::SETTING_KEY => (string) $this->settings->apiRateLimitPerMinute(),
            'app_mascot_mode'         => $this->settings->mascotMode(),
            PublicUrlResolver::SETTING_KEY => $this->settings->get(PublicUrlResolver::SETTING_KEY),
        ], $oldThemeData);

        $newData = array_merge([
            'app_subtitle'      => $subtitle,
            'app_login_notice'  => $loginNotice,
            'app_support_email' => $supportEmail,
            'mail_from_address' => $mailFromAddress,
            'mail_from_name'    => $mailFromName,
            'maintenance_mode_enabled' => $maintenanceModeEnabled,
            'maintenance_message'      => $maintenanceMessage,
            'access_log_enabled'      => $accessLogEnabled,
            'log_retention_days'      => (string) $logRetentionDays,
            ApiRateLimitMiddleware::SETTING_KEY => (string) $apiRateLimit,
            'app_mascot_mode'         => $mascotMode,
            PublicUrlResolver::SETTING_KEY => $publicUrl,
        ], $newThemeData);

        $this->settings->setMany($newData);

        // Langue de l'instance : écrite dans le fichier d'environnement (APP_LOCALE), pas en base.
        $localeError = false;
        $locale = (string) $request->post('app_locale', '');
        if ($this->instanceLocale !== null && $locale !== '' && $locale !== $this->instanceLocale->code()) {
            $oldData[InstanceLocale::ENV_KEY] = $this->instanceLocale->code();
            try {
                $this->instanceLocale->set($locale);
                $newData[InstanceLocale::ENV_KEY] = $locale;
            } catch (\InvalidArgumentException | \RuntimeException) {
                $localeError = true;
            }
        }

        $this->auditLogger->logUpdate($request, 'owner_settings.updated', 'system', null, $oldData, $newData, []);

        return Response::redirect($this->base() . '/admin/owner-settings?' . ($localeError ? 'error=instance_locale_not_saved' : 'success=1'));
    }
}
