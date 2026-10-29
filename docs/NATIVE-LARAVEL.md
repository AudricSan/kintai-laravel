# Passage à Laravel natif

Objectif : supprimer toute la couche maison (routeur, conteneur, requête/réponse, session, CSRF, moteur de vues,
migrateur, traducteur, authentification…) pour obtenir une application Laravel standard. **Aucune rétrocompatibilité** :
les instances déjà en production sont réinstallées (ou importées par `WebInstaller::import()`), et le contrat de bundle
repart en 1.0.0.

Règle de travail : chaque étape laisse la suite PHPUnit au niveau de la référence ci-dessous, et fait l'objet d'un commit.

## Référence

- 2791 tests, 1 échec d'environnement : `KintaiScriptCommandTest` (avertissement `openssl` de la ligne de commande sous
  cette installation XAMPP).

## Ce qui est natif

| Domaine | État |
| :--- | :--- |
| Routes (`routes/web.php`, `routes/api.php`), conteneur, fournisseurs, requêtes et réponses | Natif |
| Session (`StartSession`) et CSRF (`ValidateCsrfToken`, `APP_KEY` écrite par l'installateur) | Natif |
| Authentification : garde `session` sur `App\Models\User` (`remember_token`, `AuthenticateSession`, événement `Login`) | Natif |
| Migrations : `database/migrations` (5 fichiers), `php artisan migrate`, seeders `database/seeders` (`db:seed`) | Natif |
| Migrations des bundles : `loadMigrationsFrom` par le fournisseur du bundle | Natif |
| Traductions : `lang/{locale}.json` + `lang/{locale}/validation.php`, `loadJsonTranslationsFrom` pour les bundles | Natif |
| Vues : `resources/views`, `view()->make()`, `@extends`/`@section`, `view()` partagé | Natif |
| Namespaces `App\` / `Tests\`, `app/Http/Controllers`, `app/Http/Middleware`, `app/Models`, `app/Services`, `app/Repositories` | Fait |
| Bundles = service providers Laravel, contrat `kintai\Contract` 1.0.0 (alias de classes vers Laravel) | Natif |
| Base de données : `config/database.php` standard, variables `DB_*` du `.env` (écrites par l'installateur) | Natif |
| Conteneur : `Illuminate\Foundation\Application`, alias du contrat par `Container::alias()`, liaisons explicites | Natif |
| Scripts = commandes Artisan (`php artisan kintai:*`) ; `public/install.php` reste l'installateur web | Natif |

## Reste à faire

1. **Repositories** : 67 dépôts au-dessus d'Eloquent (modèles dans `app/Models`) ; à réduire aux seuls cas utiles.
2. **Routage** : `RouteBinderRegistry`, `kintai.bind` et `KintaiControllerDispatcher` (segments lisibles ↔ identifiants,
   301 vers l'URL canonique) à remplacer par `Route::bind` / liaison de modèles.
3. **Flash, anciennes valeurs, erreurs de validation** : `SessionFlash`, `OldInput`, `ValidationFeedback` à remplacer par
   `session()->flash()`, `old()` et `$errors`.
4. **Services maison à remplacer** : `MailerService` (→ `Mail`), `Log` (→ canaux de journalisation), limiteurs de débit
   (`AttemptCounter`, middlewares de limitation → `RateLimiter`), `CronRunner` (→ planificateur), `HttpException` (→ `abort()`).
5. **Environnement** : `kintai_load_env_file()` (.env.local, .env.ovh) → `APP_ENV` ; `base_url()`, `route_url()`,
   `kintai_normalize_script_name()` → `url()` / `route()` et document root `public/`.
6. **Bundles** : publier les releases, mettre à jour leurs tests et leur `composer.json`, puis le registre
   `KintaiBundle/registry.json`.
7. **Anciennes bases** : une base d'avant ces migrations a un journal `migrations` sans colonne `batch` ; l'importation
   (`WebInstaller::import()`) le remplace. Une base de développement existante doit être importée ou réinstallée, et
   son `.env` doit recevoir `APP_KEY=` puis `php artisan key:generate`.
