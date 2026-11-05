<?php

namespace Kintai\Contracts\Bundle;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Authorization\PermissionRegistry;
use Kintai\Contracts\Contract;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Profile\ProfileSectionRegistry;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Kintai\Contracts\Settings\SettingDefinition;
use Kintai\Contracts\Settings\Settings;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Kintai\Contracts\System\SystemAlert;
use Kintai\Contracts\System\SystemAlertRegistry;
use ReflectionClass;

/**
 * Base de tous les bundles Kintai. Un bundle étend cette classe, la déclare dans `extra.laravel.providers` de son
 * `composer.json` (découverte automatique de Laravel) et décrit son identité :
 *
 *     class TimeoffServiceProvider extends BundleServiceProvider
 *     {
 *         public function slug(): string { return 'timeoff'; }
 *         public function name(): string { return 'Time off'; }
 *         public function version(): string { return '1.0.0'; }
 *         public function description(): string { return 'Time off requests and approvals.'; }
 *
 *         protected function bootBundle(): void
 *         {
 *             $this->permissions('timeoff', ['view', 'create', 'approve']);
 *             $this->navigation(new NavigationItem(...));
 *         }
 *     }
 *
 * Le Core ne connaît pas le bundle par son nom : l'Owner l'active ou le désactive dans l'interface (écran « Bundles »).
 * Tant qu'il n'est pas activé, le bundle ne s'applique nulle part : ni menu, ni widget, ni route, ni vue, ni
 * traduction, ni permission... Seules les migrations sont toujours chargées, pour que `migrate` crée et garde les
 * tables : désactiver un bundle ne supprime aucune donnée.
 *
 * Arborescence attendue (chemins relatifs à `basePath()`, le dossier du package) :
 * `database/migrations`, `resources/views` (espace de noms de vues = slug), `lang` (espace de noms de traductions =
 * slug, fichiers PHP et JSON), `routes/web.php` (préfixe d'URL = slug, noms préfixés par « slug. », middleware
 * `web` et `auth`).
 *
 * Les aides `navigation()`, `widgets()`, etc. ne s'appliquent que si le bundle est activé ; un bundle les appelle dans
 * `bootBundle()` (appelé à chaque démarrage, activé ou non).
 */
abstract class BundleServiceProvider extends ServiceProvider
{
    /** Rôles du Core auxquels un bundle peut accorder des permissions par défaut. */
    public const DEFAULT_ROLES = ['manager', 'employee'];

    /**
     * Groupe de middleware de l'API des bundles, défini par le Core : langue de la requête, maintenance, limiteur,
     * authentification par jeton (Sanctum) et compte actif. Préfixe d'URL `api/v1/<slug>`, noms `api.v1.<slug>.*`.
     */
    public const API_MIDDLEWARE = 'kintai-api';

    /** @var array<string, list<string>> */
    private array $defaultPermissions = [];

    /**
     * Identifiant stable : minuscules, chiffres et tirets (`timeoff`, `shift-swap`). Préfixe des routes, espace de noms
     * des vues et des traductions, clé dans la liste des bundles activés.
     */
    abstract public function slug(): string;

    /**
     * Nom affiché (clé de traduction du Core, ou texte brut).
     */
    abstract public function name(): string;

    /**
     * Version du bundle (semver).
     */
    abstract public function version(): string;

    /**
     * Description courte affichée dans l'écran de gestion.
     */
    abstract public function description(): string;

    /**
     * Contrainte sur la version du contrat Kintai (comme `kintai/contracts` dans le `composer.json` du bundle). Le Core
     * refuse d'activer le bundle si la version installée du contrat ne la satisfait pas.
     */
    public function requiredContract(): string
    {
        return '^'.Contract::major().'.0';
    }

    /**
     * Dossier du package du bundle (celui qui contient `src/`, `database/`, `resources/`...). Par défaut le dossier
     * parent de celui du service provider.
     */
    public function basePath(string $path = ''): string
    {
        $base = dirname((string) (new ReflectionClass($this))->getFileName(), 2);

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    /**
     * Le bundle est-il activé (installé, activé par l'Owner, compatible avec le contrat) ? Hors du Core, toujours vrai.
     */
    public function isEnabled(): bool
    {
        if (! $this->app->bound(BundleActivation::class)) {
            return true;
        }

        return $this->app->make(BundleActivation::class)->isEnabled($this->slug());
    }

    /**
     * Les migrations sont toujours chargées ; vues, traductions et routes seulement si le bundle est activé.
     * Les bundles personnalisent via `bootBundle()`, pas en redéfinissant cette méthode.
     */
    final public function boot(): void
    {
        $this->loadMigrations();

        if ($this->isEnabled()) {
            $this->loadResources();
        }

        $this->bootBundle();
    }

    /**
     * Point d'entrée des bundles : appeler ici les aides d'enregistrement (`navigation()`, `widgets()`...). Appelé
     * quel que soit l'état du bundle ; les aides se taisent d'elles-mêmes tant qu'il n'est pas activé. Tout autre
     * code doit tester `isEnabled()`.
     */
    protected function bootBundle(): void {}

    // -- Aides d'enregistrement (sans effet tant que le bundle n'est pas activé) -----------------------------------

    protected function navigation(NavigationItem ...$items): void
    {
        $this->whenEnabled(function () use ($items): void {
            $registry = $this->app->make(NavigationRegistry::class);

            foreach ($items as $item) {
                $registry->register($item);
            }
        });
    }

    protected function widgets(DashboardWidget ...$widgets): void
    {
        $this->whenEnabled(function () use ($widgets): void {
            $registry = $this->app->make(DashboardWidgetRegistry::class);

            foreach ($widgets as $widget) {
                $registry->register($widget);
            }
        });
    }

    /**
     * Déclare les permissions `catégorie.action` du bundle.
     *
     * `$defaults` (depuis le contrat 1.2) : les actions accordées par défaut aux rôles `manager` et `employee` du Core,
     * par exemple `['manager' => ['view', 'create', 'manage'], 'employee' => ['view']]`. Le Core ne les applique qu'à la
     * première activation du bundle par l'Owner (jamais au rôle Owner ni aux rôles personnalisés, jamais à une
     * réactivation : les droits que l'Owner a retirés ensuite ne reviennent pas).
     *
     * @param  list<string>  $actions
     * @param  array<string, list<string>>  $defaults  Slug de rôle (`manager`, `employee`) => actions de la catégorie.
     *
     * @throws InvalidArgumentException Rôle autre que `manager` ou `employee`, ou action non déclarée.
     */
    protected function permissions(string $category, array $actions, array $defaults = []): void
    {
        $this->defaultPermissions = [];

        foreach ($defaults as $role => $roleActions) {
            if (! in_array($role, self::DEFAULT_ROLES, true)) {
                throw new InvalidArgumentException("Default permissions can only target the roles [manager, employee], [{$role}] given.");
            }

            foreach ($roleActions as $action) {
                if (! in_array($action, $actions, true)) {
                    throw new InvalidArgumentException("The default permission [{$category}.{$action}] is not declared.");
                }

                $this->defaultPermissions[$role][] = $category.'.'.$action;
            }
        }

        $this->whenEnabled(fn () => $this->app->make(PermissionRegistry::class)->register($category, $actions));
    }

    /**
     * Permissions accordées par défaut aux rôles du Core à la première activation, par slug de rôle (clés
     * `catégorie.action`). Disponible une fois le bundle démarré, activé ou non.
     *
     * @return array<string, list<string>>
     */
    public function defaultPermissions(): array
    {
        return $this->defaultPermissions;
    }

    protected function notificationTypes(NotificationType ...$types): void
    {
        $this->whenEnabled(function () use ($types): void {
            $registry = $this->app->make(NotificationTypeRegistry::class);

            foreach ($types as $type) {
                $registry->register($type);
            }
        });
    }

    /**
     * @param  (Closure(Authenticatable): bool)|null  $visible
     */
    protected function profileSection(string $key, string $label, string $route, int $order = 100, ?Closure $visible = null): void
    {
        $this->whenEnabled(fn () => $this->app->make(ProfileSectionRegistry::class)->register($key, $label, $route, $order, $visible));
    }

    protected function pendingRequests(PendingRequestProvider ...$providers): void
    {
        $this->whenEnabled(function () use ($providers): void {
            $registry = $this->app->make(PendingRequestRegistry::class);

            foreach ($providers as $provider) {
                $registry->register($provider);
            }
        });
    }

    /**
     * Fonctionnalité activable par magasin.
     */
    protected function storeFeature(string $key, string $label): void
    {
        $this->whenEnabled(fn () => $this->app->make(StoreFeatureRegistry::class)->register($key, $label));
    }

    /**
     * Libellé d'une action du journal d'activité (clés de traduction).
     */
    protected function activityAction(string $key, string $label, string $category): void
    {
        $this->whenEnabled(fn () => $this->app->make(ActivityActionRegistry::class)->register($key, $label, $category));
    }

    protected function exportDatasets(ExportDataset ...$datasets): void
    {
        $this->whenEnabled(function () use ($datasets): void {
            $registry = $this->app->make(ExportDatasetRegistry::class);

            foreach ($datasets as $dataset) {
                $registry->register($dataset);
            }
        });
    }

    protected function settings(SettingDefinition ...$definitions): void
    {
        $this->whenEnabled(fn () => $this->app->make(Settings::class)->register(...$definitions));
    }

    /**
     * Détecteur d'alertes système (voir `SystemAlertRegistry`).
     *
     * @param  Closure(): ?SystemAlert  $detect
     */
    protected function systemAlert(string $type, int $referenceId, Closure $detect): void
    {
        $this->whenEnabled(fn () => $this->app->make(SystemAlertRegistry::class)->register($type, $referenceId, $detect));
    }

    /**
     * Écoute d'un événement (du Core ou du bundle), seulement tant que le bundle est activé.
     *
     * @param  class-string|string  $event
     * @param  Closure|array{class-string, string}|string  $listener
     */
    protected function listen(string $event, Closure|array|string $listener): void
    {
        $this->whenEnabled(fn () => Event::listen($event, $listener));
    }

    private function whenEnabled(Closure $register): void
    {
        if ($this->isEnabled()) {
            $register();
        }
    }

    private function loadMigrations(): void
    {
        if (is_dir($path = $this->basePath('database/migrations'))) {
            $this->loadMigrationsFrom($path);
        }
    }

    private function loadResources(): void
    {
        if (is_dir($path = $this->basePath('resources/views'))) {
            $this->loadViewsFrom($path, $this->slug());
        }

        if (is_dir($path = $this->basePath('lang'))) {
            $this->loadTranslationsFrom($path, $this->slug());
            $this->loadJsonTranslationsFrom($path);
        }

        if (is_file($path = $this->basePath('routes/api.php')) && ! $this->app->routesAreCached()) {
            // API (depuis le contrat 1.2) : mêmes règles que l'API du Core, sous `/api/v1/<slug>`.
            $this->app->booted(function () use ($path): void {
                Route::middleware(['api', self::API_MIDDLEWARE])
                    ->prefix('api/v1/'.$this->slug())
                    ->name('api.v1.'.$this->slug().'.')
                    ->group($path);

                $routes = $this->app->make('router')->getRoutes();
                $routes->refreshNameLookups();
                $routes->refreshActionLookups();
            });
        }

        if (is_file($path = $this->basePath('routes/web.php')) && ! $this->app->routesAreCached()) {
            // Comme `loadRoutesFrom()` : une fois l'application démarrée (ses propres routes d'abord) ; tout de suite si elle l'est déjà.
            $this->app->booted(function () use ($path): void {
                Route::middleware(['web', 'auth'])
                    ->prefix($this->slug())
                    ->name($this->slug().'.')
                    ->group($path);

                // Les routes ajoutées après le démarrage ne sont pas dans les index de noms et d'actions.
                $routes = $this->app->make('router')->getRoutes();
                $routes->refreshNameLookups();
                $routes->refreshActionLookups();
            });
        }
    }
}
