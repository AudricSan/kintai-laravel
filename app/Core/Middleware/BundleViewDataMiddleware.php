<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\Log;
use kintai\UI\ViewRenderer;

/**
 * Variables partagées avec toutes les vues des pages authentifiées :
 * - `managed_store_ids` (Core) : null pour l'Owner, magasins gérés pour un gérant (liens par magasin de la navigation) ;
 * - celles déclarées par les bundles actifs (point d'extension Bundle::viewVariables(), contrat 1.5), par exemple
 *   `daily_report_staff_stores` (daily-report) ou `unread_messages_count` (messaging).
 *
 * Remplace DailyReportNavMiddleware et MessageCountMiddleware.
 */
final class BundleViewDataMiddleware implements MiddlewareInterface
{
    /** Variables que seul le Core partage : un bundle ne peut pas les déclarer. */
    public const RESERVED = [
        'auth_user', 'auth_is_manager', 'managed_store_ids', 'store_features', 'user_can', 'route_visible',
        'BASE_URL', 'content', 'title', 'user', 'view',
    ];

    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $this->container->make(AuthService::class);
        if (!$auth->check()) {
            return $next($request);
        }

        $user = $auth->user();
        $view = $this->container->make(ViewRenderer::class);

        $isOwner = !empty($user['is_admin']);
        $isManager = !$isOwner && $auth->isManager();
        if ($isOwner || $isManager) {
            $view->share('managed_store_ids', $isOwner ? null : $auth->managedStoreIds());
        }

        foreach ($this->variables($user, $isOwner || $isManager) as $name => $value) {
            $view->share($name, $value);
        }

        return $next($request);
    }

    /**
     * Variables déclarées par les bundles actifs. Chaque variable est isolée : une exception la retire (journalisée).
     *
     * @return array<string, mixed>
     */
    public function variables(array $user, bool $isManager): array
    {
        $registry = $this->container->has(ExtensionRegistry::class) ? $this->container->make(ExtensionRegistry::class) : null;
        $declared = $registry?->viewVariablesByBundle() ?? [];

        $values = [];
        foreach ($declared as $slug => $variables) {
            foreach ($variables as $name => $resolve) {
                if (in_array($name, self::RESERVED, true) || array_key_exists($name, $values)) {
                    Log::warning('bundle_extension_invalid', ['bundle' => $slug, 'point' => 'viewVariables', 'variable' => $name]);
                    continue;
                }
                try {
                    $values[$name] = $resolve($user);
                } catch (\Throwable $e) {
                    Log::warning('bundle_extension_failed', ['bundle' => $slug, 'point' => 'viewVariables', 'variable' => $name, 'error' => $e->getMessage()]);
                }
            }
        }

        return $values;
    }
}
