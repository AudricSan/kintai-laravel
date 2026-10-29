# Passage à Laravel natif

Objectif : supprimer la couche maison (routeur, conteneur, requête/réponse, session, CSRF, pont `KintaiBridge`) pour
obtenir une application Laravel standard. **Aucune rétrocompatibilité** : les instances déjà en production sont
réinstallées (éventuellement avec un script de migration des données), et le contrat de bundle repart en 1.0.0.

Règle de travail : chaque étape laisse la suite PHPUnit au niveau de la référence ci-dessous, et fait l'objet d'un commit.

## Référence

- 2905 tests, 2 échecs d'environnement : `KintaiScriptCommandTest` (avertissement `openssl` de la ligne de commande
  sous cette installation XAMPP) et `ViewRendererTest::testAMissingViewIsReported` (séparateur de chemin Windows).

## Avancement

| Étape | État |
| :--- | :--- |
| Tests sur l'application Laravel complète (`tests/bootstrap.php`) | Fait |
| 1. Session et CSRF natifs (`session()`, jeton `_token`) | Fait |
| 1. Garde d'authentification | Garde Laravel personnalisée (`KintaiGuard`) au-dessus d'`AuthService` (choix « option A ») |
| 2. Requête/réponse Laravel dans les contrôleurs, middlewares et services HTTP | Fait |
| 3. Middlewares à signature Laravel, pile globale dans `bootstrap/app.php` | Fait |
| 4. Routes natives (`routes/web.php`, `routes/api.php`), rendu des erreurs, journal d'accès | Fait |
| 5. Conteneur et fournisseurs natifs (`Core\Application`, `Container`, `ServiceProvider` supprimés) | Fait |
| 8. Bundles = service providers Laravel, contrat 1.0.0, 13 bundles convertis | Fait (voir ci-dessous) |
| 9. Scripts en commandes Artisan | Les scripts de `scripts/` sont exposés par `php artisan kintai:*` ; `public/install.php` reste l'installateur web |
| 6. Namespace `kintai\` → `App\` et réorganisation (`Http/Controllers`, `resources/views`, `Models`) | À faire (renommage global) |
| 7. Migrations reconstruites (peu de fichiers, installation neuve) et repositories réduits | À faire |

## Ce qui est natif

- **Routes** : `Route::get(...)->name()->middleware()`. Deux macros ajoutées aux routes de Laravel :
  `->permission('shifts.update')` (règle RBAC lue par `PermissionMiddleware` / `ApiPermissionMiddleware`) et
  `->bindSegments(['id' => 'store'])` (segment lisible → identifiant, 301 vers l'URL canonique, 404 rendue après les
  middlewares : `BindRouteParameters` + `KintaiControllerDispatcher`).
- **Pile HTTP** : les middlewares de `config/middleware.php` sont dans la pile globale de `bootstrap/app.php`, précédés de
  `AccessLog` (journal d'accès écrit à la fin de la réponse). `ErrorResponder` rend les exceptions (pages `errors.*`, JSON
  pour l'API) via `withExceptions()`.
- **Conteneur et fournisseurs** : `bootstrap/providers.php` liste `KintaiServiceProvider`, puis les fournisseurs de base de
  données, dépôts, authentification, services, licence et bundles. Les scripts et l'installateur obtiennent l'application
  complète par `bootstrap/boot.php`.
- **Bundles** : `Bundle` étend `Illuminate\Support\ServiceProvider`. `BundleManager` les enregistre avec
  `$app->register()` ; leurs routes (`routes.php`, en `Route::`) sont chargées après celles du Cœur ; leurs vues par
  `loadViewsFrom()` dans le moteur de vues de l'application. Le contrat (`kintai\Contract\*`) est en 1.0.0 : les alias
  `Request`, `Response` et `Container` pointent vers Laravel.
- **Requêtes longues (SSE)** : `response()->stream()` (mise à jour, installation de bundle).

## Bundles

Les 13 dépôts de `KintaiBundleDev/` ont été convertis (routes en `Route::`, requêtes/réponses de Laravel, `getVersion()`
lue dans `bundle.json`, `kintai_contract` à `^1.0`, version majeure suivante). Chargés en local depuis `storage/bundles/` :
13 bundles, 178 routes. **Pas encore commités** dans leurs dépôts ; leurs propres tests (`tests/`) n'ont pas été relancés :
ils s'appuient sur le Cœur installé dans leur `vendor/` et demandent un `composer update` une fois le Cœur publié.

## Reste à faire

1. **Migrations** : reconstruire `database/migrations/php` (107 fichiers) en quelques migrations de création, pour une
   installation neuve, avec les deux migrations de données (rôles par défaut, registre officiel de bundles). Prévoir un
   petit script de migration de l'ancienne base vers la nouvelle.
2. **Repositories** : 67 dépôts à réduire (Eloquent porte les requêtes ; garder les interfaces seulement là où un service
   en a besoin pour les tests).
3. **Namespace et structure** : `kintai\` → `App\`, `UI/Controller` → `Http/Controllers`, `UI/View` → `resources/views`,
   `Domain/Eloquent` → `Models`. Les alias du contrat de bundle (`kintai\Contract\*`) restent le nom public pour les
   bundles.
4. **Vues** : `ViewRenderer` garde son propre moteur (directive `@includeInScope`, vues `.php` et Blade) ; il est
   branché comme service `view` de l'application. À supprimer quand les vues deviennent des vues Blade standard.
5. **Authentification** : remplacer `AuthService` + `KintaiGuard` par un guard `session` sur le modèle `User`.
6. **Bundles** : commiter les 13 dépôts, mettre à jour leurs tests et `composer.json`, publier les releases et mettre à jour
   le registre `KintaiBundle/registry.json`.
