# Passage à Laravel natif

Objectif : supprimer toute la couche maison (routeur, conteneur, requête/réponse, session, CSRF, moteur de vues,
migrateur, traducteur, authentification…) pour obtenir une application Laravel standard. **Aucune rétrocompatibilité** :
les instances déjà en production sont réinstallées (ou importées par `WebInstaller::import()`), et le contrat de bundle
repart en 1.0.0.

Règle de travail : chaque étape laisse la suite PHPUnit au niveau de la référence ci-dessous, et fait l'objet d'un commit.

## Référence

- 3588 tests (cœur + bundles), 1 échec d'environnement : `KintaiScriptCommandTest` (avertissement `openssl` de la ligne de commande sous
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
| Commandes Artisan natives (`kintai:cron`, `cron-token`, `contract`, `check-wiki`, `check-translations`, `new-bundle`) ; `public/install.php` et `scripts/provision.php` restent les installateurs, `seed-demo-data`, `clean-orphaned-data` et `generate-favicons` des scripts d'entretien | Natif / scripts |
| Journal d'activité : canaux Monolog `business` (défaut), `audit`, `access` (`config/logging.php`), écrits dans `activity_log` par `App\Logging\ActivityLogHandler` ; `Log::info()` de Laravel partout | Natif |
| CI : `tests.yml` (PHPUnit cœur + bundles, phpstan, parcours HTTP), `bundles-release.yml`, manifestes des bundles testés (`MonorepoManifestsTest`) | Natif |
| Flash, anciennes valeurs, erreurs de validation : `session()->flash()`, `withErrors()` / `withInput()`, `$errors` (layouts) | Natif |
| Routage lisible : `Route::bind('store'|'employee')` (`App\Routing\RouteSegments`), `SubstituteBindings`, 301 des anciens liens | Natif |
| Limitation de débit : `Illuminate\Cache\RateLimiter` (connexion, formulaires sensibles, API) | Natif |
| E-mail : mailer de Laravel (Symfony Mailer), `config/mail.php` standard (`MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_*`) | Natif |
| Environnement : `.env` chargé par Laravel seul (plus de `kintai_load_env_file`, `.env.local`, `.env.ovh`) | Natif |
| Erreurs HTTP : `App\Exceptions\HttpException` étend l'`HttpException` de Symfony (celle de `abort()`) | Natif |

## Choix assumés (conservés)

- **Repositories** : conversion en cours vers les modèles Eloquent utilisés directement (casts, méthodes de modèle,
  tests sur base SQLite en mémoire avec `kintai_test_database()` / `kintai_test_migrate()` / `kintai_test_user()`).
  Faits : `TimesheetApproval`, `ImportAlias`, `NotificationPreference`, `ShiftWeekTemplate`, `CronToken`, `PasswordReset`,
  `RoleDashboardLayout`. Restent 26
  interfaces (`app/Repositories`), de la plus petite à la plus utilisée : `RouteSlug`, `BundleRegistry`,
  `CalendarSync`, `UserDashboardLayout`, `InstalledBundle`, `WebPushSubscription`,
  `DevicePushToken`, `ApiToken`, `Notification`, `IcalToken`, `UserDashboardPrefs`, `UserNavPrefs`, `Log`, `Availability`,
  `UserShiftTypeRate`, `AppSettings`, `ShiftType`, `Shift`, `Role`, `RoleAssignment`, `Store`, `StoreUser`, `User`
  (227 fichiers). Chaque conversion remplace aussi les doubles de test par des tests sur base réelle.
- **Tâches planifiées** (plus de `CronRunner`) : commandes Artisan `kintai:*` planifiées par `Schedule` (`routes/console.php` pour le Core, `register()` du bundle pour les siennes) ;
  `/cron/run/{tâche}` avec jeton lance la commande `kintai:{tâche}` si elle est planifiée, pour les hébergements qui ne savent appeler qu'une URL.
- **Messages `?success=` / `?error=`** : codes dans l'URL traduits par `Flash` (35 vues, 139 redirections) ; les textes
  libres passent par la session. À convertir en `with('status', …)` si l'on veut la même mécanique partout.
- **`base_url()`, `route_url()`, `kintai_normalize_script_name()`** : glue de sous-dossier (XAMPP, OVH mutualisé) ; la
  racine web peut être `public/` sur un serveur dédié.

## Reste à faire

1. **Bundles** : les 13 bundles vivent dans `bundles/<slug>/` (monorepo, historique git conservé). Releases :
   `.github/workflows/bundles-release.yml` publie `<slug>-vX.Y.Z` ; le registre `registry.json` (dépôt KintaiBundle)
   pointe désormais vers `https://github.com/AudricSan/kintai-laravel/tree/main/bundles/<slug>` (fait ; `versions` se remplit à la première release, `scripts/sync-versions.js` gère les tags `<slug>-vX.Y.Z`). Les anciens dépôts
   `kintai-bundle-*` peuvent être archivés. Tests : `vendor/bin/phpunit --testsuite Bundles`.
2. **Anciennes bases** : une base d'avant ces migrations a un journal `migrations` sans colonne `batch` ; l'importation
   (`WebInstaller::import()`) le remplace. Une base de développement existante doit être importée ou réinstallée, et
   son `.env` doit recevoir `APP_KEY=` puis `php artisan key:generate`.
3. **Variables d'e-mail** : `MAIL_DRIVER` devient `MAIL_MAILER` (`smtp` ou `sendmail`) et `MAIL_ENCRYPTION` devient
   `MAIL_SCHEME` (`smtps` = TLS direct, `smtp` = STARTTLS). Un `.env` existant doit être mis à jour.