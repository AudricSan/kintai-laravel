# Passage à Laravel natif

Objectif : supprimer le pont `KintaiBridge` et toute la couche maison (routeur, conteneur, requête/réponse, session,
CSRF, middlewares, repositories), pour obtenir une application Laravel standard. La compatibilité avec l'ancien Kintai
et avec les bundles actuels (contrat 1.31) peut être cassée.

Règle de travail : chaque étape laisse la suite PHPUnit au niveau de la référence ci-dessous, et fait l'objet d'un commit.

## Référence de départ (branche `main`, 2026-10-29)

- 3000 tests, 9 erreurs, 12 échecs préexistants (21 cas, dont des séparateurs de chemin Windows).
- 56 contrôleurs, 99 services, 67 repositories, 20 middlewares, 39 modèles Eloquent dans `app/Domain/Eloquent`.
- `$_SESSION` n'est lu que dans 9 fichiers de `app/`, mais 39 fichiers de tests y touchent.

## Étapes

1. **Session, CSRF et auth natifs.** Remplacer `$_SESSION` par `session()`, `SessionMiddleware`/`CsrfMiddleware` par
   `StartSession` + `VerifyCsrfToken`, `AuthService` + `KintaiGuard` par un vrai guard `session` sur le modèle `User`
   (`Auth::attempt`, `Auth::login`, `Hash`). Supprimer `NativeSessionStore`. *Effet : tout le monde est déconnecté une fois.*
2. **Requête et réponse natives.** Les 56 contrôleurs prennent `Illuminate\Http\Request` et renvoient des réponses
   Laravel (`view()`, `redirect()`, `response()->json()`). Supprimer `Core\Request`, `Core\Response`, `ViewRenderer`,
   `KintaiPhpEngine`, `OldInput`, `SessionFlash` (remplacés par `old()` et `->with()`).
3. **Middlewares Laravel.** Les 20 middlewares deviennent des classes `handle($request, $next)` standard, groupes
   `web` et `api`, alias dans `bootstrap/app.php`. Throttling via `RateLimiter`, permissions via `Gate`/policies.
4. **Routes natives.** `routes/kintai.php` devient `routes/web.php` + `routes/api.php` avec `Route::` standard
   (`->name()`, `->middleware()`). Supprimer `Core\Router`, `Core\Route`, `KintaiRoutes`, `KintaiRouteAction`,
   `ResolveKintaiRoute`, `KintaiBridge`, `KintaiKernel`.
5. **Conteneur et providers.** Supprimer `Core\Container`, `Core\ServiceProvider`, `AliasAwareContainer` ; tout passe
   par les service providers Laravel et l'injection automatique.
6. **Structure et namespace.** `kintai\` devient `App\` ; `UI/Controller` → `Http/Controllers`, `UI/View` →
   `resources/views`, `Domain/Eloquent` → `Models`, `lang/*.json` conservés, `config/*.php` ramenés aux fichiers Laravel
   (`session`, `auth`, `mail`, `queue`, `cache`, `filesystems`), suppression de `.env.local`/`.env.ovh` spéciaux.
7. **Persistance.** Les 67 repositories sont réduits : les modèles Eloquent portent les requêtes, les repositories qui
   n'ajoutent rien sont supprimés ; les migrations passent de `database/migrations/php` à `database/migrations`.
8. **Bundles.** Un bundle devient un package Composer ou un module avec son `ServiceProvider` Laravel (routes, vues,
   migrations, traductions chargées par les méthodes standard). Suppression de `InstalledBundleAutoloader`,
   `BundleManager` et du contrat 1.31 ; dépôt `kintai-laravel-bundles` adapté.
9. **Installation et mise à jour.** `public/install.php` et `scripts/*.php` remplacés par des commandes Artisan ;
   planificateur dans `routes/console.php`.

## Ordre et dépendances

Les étapes 1 à 5 sont séquentielles. 6 se fait en une seule passe (renommage global) une fois 1 à 5 terminées. 7 et 8
peuvent avancer en parallèle après 6.

## Avancement (2026-10-29)

Référence : 2998 tests, 2 échecs d'environnement (avertissement `openssl` en ligne de commande ; séparateur de chemin
Windows dans `ViewRendererTest`, qui disparaît avec `ViewRenderer`).

| Étape | État |
| :--- | :--- |
| Tests sur l'application Laravel complète (`tests/bootstrap.php`) | Fait |
| 1. Session et CSRF natifs (`session()`, jeton `_token`, contrat de bundle 2.0.0) | Fait |
| 1. Garde d'authentification | Garde Laravel personnalisée (`KintaiGuard`) au-dessus d'`AuthService` : conservée (option A) |
| 2. Requête et réponse natives dans les 56 contrôleurs, 20 middlewares et services HTTP | Fait |
| 3. Middlewares à signature Laravel | Fait (encore exécutés par le pont, voir ci-dessous) |
| 4. Routes natives, suppression du routeur et du pont | À faire |
| 5. Conteneur et providers natifs | À faire |
| 6. Namespace `kintai\` → `App\` et structure Laravel | À faire |
| 7. Repositories réduits au profit d'Eloquent, migrations dans `database/migrations` | À faire |
| 8. Les 13 bundles en service providers Laravel | À faire |
| 9. Installateur et `scripts/*.php` en commandes Artisan | À faire |

### Ce qui reste de maison (à supprimer aux étapes 4, 5 et 8)

- `app/Core/Application.php` (routage, rendu des erreurs, journal d'accès), `Router`, `Route`, `Container`,
  `ServiceProvider`, `MiddlewarePipeline`, `MiddlewareInterface`, `Request`, `Response`, `NativeCall`.
- Le pont : `app/Http/KintaiBridge`, `KintaiRoutes`, `KintaiRouteAction`, `Middleware/*` (Kernel, KintaiMiddleware,
  ResolveKintaiRoute, CarriedResponse).
- `routes/kintai.php` (DSL maison, avec `permission:` et paramètres typés `{id:store}`) : à convertir en
  `routes/web.php` et `routes/api.php`.

### Plan détaillé de l'étape 4

1. Générer `routes/web.php` et `routes/api.php` en exécutant `routes/kintai.php` sur un enregistreur de routes
   (`->name()`, `->middleware()`, macro `->permission()` et macro `->kintaiBind()` pour `{id:store}`).
2. Les 11 middlewares globaux de `config/middleware.php` passent dans la pile globale de `bootstrap/app.php`.
3. Paramètres typés : un middleware de résolution (avant ceux de la route) remplace les segments par les identifiants et
   garde la 301/404 différée dans un attribut ; un second middleware, juste avant le contrôleur, la rend (les contrôles
   d'accès doivent passer avant que l'on révèle l'existence d'un magasin).
4. Rendu des erreurs et journal d'accès (`Application::handleException`, `renderError`, `logAccess`) portés dans
   `withExceptions()` et un middleware terminable.
5. `route_url()` réimplémenté sur la collection de routes de Laravel.
6. Les routes des bundles passent par un enregistreur transitoire qui les déclare à Laravel, jusqu'à l'étape 8.

### Outillage utilisé pour la conversion

Les conversions automatiques (`Response::redirect` → `redirect()->away`, `$request->param` → `$request->route`, etc.)
ont été faites par des scripts jetables ; leurs règles sont dans `app/Http/Macros.php` (macros Request/Response) et dans
l'historique git. `redirect()->away()` est volontaire : `redirect('/x')` préfixe la racine de l'URL et doublerait le
sous-dossier déjà ajouté par `base_url()`.
