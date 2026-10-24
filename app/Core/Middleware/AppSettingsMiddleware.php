<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\StorageUsageService;
use kintai\Core\Services\UpdateService;
use kintai\UI\ViewRenderer;

final class AppSettingsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = $this->container->make(AppSettingsService::class);
            $view     = $this->container->make(ViewRenderer::class);

            $view->share('app_subtitle',     $settings->subtitle());
            $view->share('app_login_notice', $settings->loginNotice());
            $view->share('app_theme_color_style', $settings->themeColorStyle());
            $view->share('app_support_email', $settings->supportEmail());
            $view->share('app_maintenance_mode_enabled', $settings->maintenanceModeEnabled());
            $view->share('app_version', $this->container->make(UpdateService::class)->getCurrentVersion());
            // Sans URL publique, les e-mails de réinitialisation du mot de passe ne partent pas : l'Owner en est averti.
            $view->share('app_public_url_missing', $this->container->make(PublicUrlResolver::class)->resolve() === null);
            // Plus de bundles actifs que le quota de la licence (installation antérieure, licence expirée) : l'Owner en
            // est averti ; il peut réduire un bundle à la fois sur /admin/bundles.
            $maxBundles = $this->container->make(PlanLimitService::class)->maxActiveBundles();
            $activeBundles = count($this->container->make(BundleManager::class)->getBundles());
            $view->share('setup_wizard_pending', $settings->get(\kintai\Core\Services\SetupWizard::PENDING_KEY) === '1');
            $view->share('bundle_quota_overflow', $maxBundles !== null && $activeBundles > $maxBundles
                ? ['active' => $activeBundles, 'max' => $maxBundles] : null);
        } catch (\Throwable) {
            // Table absente (avant migration) ou DB non disponible — on ignore.
        }

        // Stockage du serveur presque plein : bandeau réservé à l'Owner. Séparé du bloc ci-dessus, la mesure du disque
        // ne dépend pas de la base et ne doit jamais empêcher la page de s'afficher.
        try {
            $this->container->make(ViewRenderer::class)->share('storage_low', $this->container->make(StorageUsageService::class)->lowStorage());
        } catch (\Throwable) {
        }

        return $next($request);
    }
}
