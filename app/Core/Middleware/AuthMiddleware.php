<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Router;
use kintai\Core\Services\EmployeeStatsService;
use kintai\Core\Services\TranslationService;
use kintai\UI\ViewRenderer;

/**
 * Middleware d'authentification.
 * Redirige vers /login si l'utilisateur n'est pas connecté.
 * Attache l'utilisateur courant à la requête ET le partage avec les vues.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);

        if (!$auth->check()) {
            return Response::redirect(base_url() . '/login');
        }

        $user = $auth->user();

        // Rend l'utilisateur disponible dans la requête (pour les contrôleurs)
        $request->setAttribute('auth_user', $user);

        // Partage avec toutes les vues (pour le layout)
        $view = $this->container->make(ViewRenderer::class);
        $view->share('auth_user', $user);
        $view->share('auth_is_manager', $auth->isManager());

        // Valeur par défaut de managed_store_ids pour les routes sans PermissionMiddleware
        // (/employee/*, /profile, /docs...), où il ne serait sinon jamais défini. Sur les
        // routes couvertes par PermissionMiddleware, celui-ci s'exécute après et écrase
        // toujours cette valeur avec son propre calcul, plus fin (scope de LA permission
        // réellement requise par la route) — ce repli ne sert donc qu'ailleurs.
        if (empty($user['is_admin'])) {
            $managedStoreIds = $auth->managedStoreIds();
            if ($managedStoreIds !== []) {
                $view->share('managed_store_ids', $managedStoreIds);
            }
        }

        // Permissions fines RBAC : l'utilisateur détient-il cette clé quelque
        // part ? Partagé ici (et non par PermissionMiddleware) pour que la
        // navigation (sidebar, bottom-nav) soit identique sur TOUTES les pages
        // authentifiées, y compris celles sans contrôle de permission fine
        // (dashboard employé, messages, rapports journaliers, profil, docs…).
        $permissions = $this->container->make(PermissionService::class);
        $isOwner = !empty($user['is_admin']);
        $view->share('user_can', fn(string $permissionKey): bool
            => $isOwner || $permissions->can($user, $permissionKey, null));

        // Visibilité de nav pilotée par la permission RÉELLEMENT déclarée sur la route
        // (Route::$permission, RBAC-V2) plutôt qu'une clé recopiée à la main à côté du
        // lien — élimine la classe de bug où la nav affiche un lien que la route
        // bloquerait ensuite (ex. bottom-nav "Messages"/"Rapports journaliers" n'avait
        // jusqu'ici aucun contrôle de permission alors que leurs routes en exigent une).
        // Route absente/sans permission déclarée/'public' : toujours visible.
        $router = $this->container->make(Router::class);
        $view->share('route_visible', function (string $routeName) use ($router, $isOwner, $permissions, $user): bool {
            $route = $router->routeByName($routeName);
            $rule  = $route?->permission;
            if ($rule === null || $rule === 'public') {
                return true;
            }
            $key = is_array($rule) ? (string) ($rule['perm'] ?? '') : $rule;
            return $key === '' || $isOwner || $permissions->can($user, $key, null);
        });

        // Langue courante
        $view->share('locale', $this->container->make(TranslationService::class)->getLocale());
        $activeLanguages = $this->container->make(LanguageRepositoryInterface::class)->findAllActive();
        $view->share('active_languages', $activeLanguages);
        // Langue de l'instance, proposée dans le profil (« suivre la langue de l'instance »).
        $instanceCode = \kintai\Core\Services\InstanceLocale::resolve($this->container->make(LanguageRepositoryInterface::class));
        $view->share('instance_language_name', (string) (array_column($activeLanguages, 'name', 'code')[$instanceCode] ?? $instanceCode));

        // Fonctionnalités activées pour le store de l'utilisateur (null = tout activé)
        $storeFeatures = null;
        if (empty($user['is_admin'])) {
            $storeUsers = $this->container->make(StoreUserRepositoryInterface::class);
            $memberships = $storeUsers->findByUser((int) ($user['id'] ?? 0));
            if (!empty($memberships)) {
                $storeRepo = $this->container->make(StoreRepositoryInterface::class);
                // Aucune ligne en base = jamais configuré → tout activé (null)
                $features = $storeRepo->getFeatures((int) $memberships[0]['store_id']);
                $storeFeatures = $features !== [] ? $features : null;
            }
        }
        $view->share('store_features', $storeFeatures);

        // Statistiques du mois (widget salaire estimé, barre latérale vue employé) —
        // partagées sur toutes les pages, pour tout le monde y compris l'Owner : un
        // Owner qui prend lui-même des shifts doit voir ses propres heures/paie comme
        // n'importe qui d'autre. calculate() dégrade proprement (0h, pas de détails)
        // pour qui n'a aucun shift ce mois-ci — le widget lui-même (_topbar.php) ne
        // s'affiche de toute façon que si la donnée existe.
        $employeeStats = $this->container->make(EmployeeStatsService::class);
        $month = (string) ($request->query('month') ?? '');
        $view->share('employee_month_stats', $employeeStats->calculate((int) ($user['id'] ?? 0), $month));

        // Préférences de navigation (tous les utilisateurs)
        $navPrefs = $this->container->make(UserNavPrefsRepositoryInterface::class);
        $uid = (int) ($user['id'] ?? 0);
        $view->share('user_nav_hidden', $navPrefs->getHidden($uid));
        $view->share('user_nav_section_order', $navPrefs->getSectionOrder($uid));
        $view->share('user_bottom_nav_items', $navPrefs->getBottomNavItems($uid));

        return $next($request);
    }

}
