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
| Commandes Artisan natives (`kintai:cron`, `cron-token`, `contract`, `check-wiki`, `check-translations`, `new-bundle`, `clean-orphans`) ; `provision`, `seed-demo-data`, `install-bundle-archive` ; `public/install.php` reste l'installateur web, `generate-favicons` un script d'entretien | Natif / scripts |
| Messages de retour : `redirect(...)->with('success'|'error', code)` (flash de session), lus par `Flash::fromSession()` et `session()` ; plus de `?success=` / `?error=` dans les URL | Natif |
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
  Faits (30) : `TimesheetApproval`, `ImportAlias`, `NotificationPreference`, `ShiftWeekTemplate`, `CronToken`, `PasswordReset`,
  `RoleDashboardLayout`, `BundleRegistry`, `InstalledBundle`, `UserDashboardLayout`, `CalendarSync` (3 modèles),
  `WebPushSubscription`, `DevicePushToken`, `RouteSlug`, `ApiToken`, `Notification`, `IcalToken`, `UserDashboardPrefs`,
  `UserNavPrefs`, `Log` (lecture, `ActivityEntry`), `Availability`, `UserShiftTypeRate`, `AppSettings` (`AppSetting`),
  `ShiftType`, `Shift`, `Role` (tests : `kintai_test_roles()` / `kintai_test_role_table()`), `RoleAssignment` (`kintai_test_assignments()`), `StoreUser` (`kintai_test_store_users()`), `Store` (`kintai_test_stores()`, `kintai_test_store_features()`), `User` (`kintai_test_users()`, `kintai_test_remember_token()`). Langues : table `languages` (`Language`, `LanguageSeeder`, `kintai_test_languages()`) ; l'éditeur de traductions lit et écrit `lang/{locale}.json` par `TranslationManagementService`.
  Interfaces à implémentation unique supprimées (`StoreService`, `ShiftService`, `StoreStatsService`, `SystemAlertDetectors`, transport HTTP → `Http::fake()`). Restent, car polymorphes : `SetupStepInterface`, `ComponentInterface`, `RouteParamBinder`, fournisseurs du contrat.
  Chaque conversion remplace aussi les doubles de test par des tests sur base réelle. Outil : les méthodes du
  repository deviennent des méthodes statiques du modèle (noms réservés par Eloquent renommés : `save` → `put`…).
- **Tâches planifiées** (plus de `CronRunner`) : commandes Artisan `kintai:*` planifiées par `Schedule` (`routes/console.php` pour le Core, `register()` du bundle pour les siennes) ;
  `/cron/run/{tâche}` avec jeton lance la commande `kintai:{tâche}` si elle est planifiée, pour les hébergements qui ne savent appeler qu'une URL.
- **Messages `?success=` / `?error=`** : codes dans l'URL traduits par `Flash` (35 vues, 139 redirections) ; les textes
  libres passent par la session. À convertir en `with('status', …)` si l'on veut la même mécanique partout.
- **`base_url()`, `route_url()`, `kintai_normalize_script_name()`** : glue de sous-dossier (XAMPP, OVH mutualisé) ; la
  racine web peut être `public/` sur un serveur dédié.

## Ce qui n'est pas encore natif

Inventaire au 31/10/2026 (code de `app/`, hors bundles). Eloquent n'est plus « en cours » : les 30 repositories à table sont des modèles.

| Reste | Détail | Piste |
| :--- | :--- | :--- |
| `AuthService` | la connexion est native (`Auth::attempt()` + `KintaiUserProvider`, pilote « kintai ») ; reste `user()` (tableau + `is_admin` RBAC), `isAdmin()`, `managedStoreIds()` et le rappel de mot de passe | `Gate::before` / méthodes du modèle `User` quand les contrôleurs liront l'objet Eloquent plutôt qu'un tableau |
| `$_SERVER` (URL de base) | `base_url()` lit encore SCRIPT_NAME (glue de sous-dossier) ; schéma/hôte, cookie de langue et User-Agent sont natifs (`request()`, `Cookie::queue()`) | `request()->getBasePath()` ; une trentaine de tests posent SCRIPT_NAME |
| Macros de réponse | `csv`, `jsonDownload`, `pdf`, `ical`, `fileContents` (en-têtes de téléchargement en mémoire, macros Laravel) ; les fichiers passent par `response()->file()` / `download()` | `ErrorResponder` pourrait devenir un gestionnaire d'exceptions par classe |
| Validation métier des contrôleurs | ~40 `throw new ValidationException` manuels | `FormRequest` (les tests appellent aujourd'hui les contrôleurs directement) |
| `RequestMemo` | mémo par requête des lectures de comptes, rôles et droits (vidé à chaque écriture, trait `FlushesRequestMemo`) | disparaît avec les relations Eloquent chargées une fois sur le modèle `User` ; la `Capsule` est supprimée (services sur `DB` / `Connection`) |

## Audit du 31/10/2026

Le rapport `Audit_Kintai_Migration_Laravel_Securite_2026-10-31.md` est traité ainsi :

| Constat | État |
| :--- | :--- |
| SEC-01 installateur sans CSRF | Corrigé : jeton en cookie `SameSite=Strict` + champ caché, installation exclusive (verrou de fichier) |
| SEC-02 mot de passe `0000` | Corrigé : mot de passe temporaire aléatoire, colonne `users.must_change_password`, pages web renvoyées vers le profil tant qu'il n'est pas changé |
| SEC-03 jeton iCal dans les journaux | Corrigé : jetons `/ical/…` et `/reset-password/…` masqués dans le journal d'accès |
| SEC-04 bundles non épinglés | Corrigé : installation refusée sans commit épinglé (`KINTAI_ALLOW_UNPINNED_BUNDLES=true` pour l'autoriser) |
| SEC-05 import par `require` | Corrigé : `LiteralConfig` lit la configuration comme des données ; sources limitées aux dossiers voisins ou à `KINTAI_IMPORT_ROOTS` |
| SEC-06 `HTTP_HOST` à l'installation | Corrigé : adresse publique confirmée dans le formulaire |
| SEC-07 cookie de session | Corrigé : `Secure` automatique en HTTPS |
| SEC-08 SSRF (`HttpFetcher`) | Corrigé : `UrlGuard` (HTTPS, IP publiques, redirections revalidées et limitées, taille bornée) |
| SEC-09 extraction ZIP | Corrigé : `SafeZip` (chemins, liens symboliques, taille, taux de compression) |
| SEC-10 jeton cron en URL | Corrigé : en-tête `Authorization: Bearer` seulement (`CRON_ALLOW_QUERY_TOKEN=true` pour l'ancien comportement) |
| SEC-11 verrouillage de compte | Accepté (compromis documenté) |
| SEC-12 pile de middlewares | Partiel : `ValidatePostSize` rétabli ; ni CORS ni `TrimStrings`, volontairement |
| SEC-13 CSP | Corrigé : `base-uri` et `connect-src` |
| SEC-14 `$guarded = []` | Traité à l'entrée : toute écriture venant d'une requête passe par `PayloadValidator::validate()` (ne renvoie que les champs déclarés dans les règles), une liste blanche explicite (`StoreUserController::allowedFields`, API timeclock) ou des tableaux construits champ par champ ; aucun `put()`/`create()`/`fill()` ne reçoit `$request->all()`. Passer les 56 modèles en `$fillable` ne ferait que recopier la liste des colonnes (les modèles écrivent déjà `id`, `password_hash`, etc. par `put()`) : non fait, à revoir si un endpoint transmet un jour un corps brut |
| SEC-15 journaux | Fait : `App\Support\LogScrubber` masque Bearer, mots de passe, jetons, identifiants d'URL et liens `/ical/…`, `/reset-password/…` dans les messages d'erreur ; les piles d'appels n'ont plus leurs arguments (`ErrorResponder::log()`) |
| Couche « DAO » (méthodes statiques `findBy…/put/remove` renvoyant des tableaux) | À faire : principal chantier restant ; les 30 modèles du Cœur et ceux des 12 bundles l'exposent encore |
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
## Validation (31/10/2026)

Le contrôle des saisies est celui du validateur de Laravel : `PayloadValidator` (corps JSON de l'API) et `StoreValidator`
(formulaire magasin) construisent un `Validator::make(...)` ; `ColorRule` est une règle `ValidationRule`. Les erreurs sont
portées par `App\Exceptions\ValidationException::check()` (422, un message par champ), alias du contrat des bundles.
`ValidationResult` et `ValidatorInterface` n'existent plus. Les refus métier des contrôleurs (unicité, appartenance à un
magasin, état d'une demande) restent des `ValidationException` explicites : ce ne sont pas des contrôles de format.
Les dépôts JSON de langues et de traductions (`lang/*.json`) restent des classes de fichiers : il n'y a pas de table.

## Scripts → Artisan (31/10/2026)

`scripts/provision.php`, `scripts/seed-demo-data.php` et `scripts/ci/install-bundle-archive.php` sont remplacés par
`php artisan kintai:provision`, `kintai:seed-demo-data` et `kintai:install-bundle-archive`. `scripts/generate-favicons.php`
(outil GD d'images, sans framework) et `scripts/set-release-version.php` (publication) restent des scripts autonomes ;
`bootstrap/boot.php` et `standalone.php` ne servent plus qu'à l'installateur web, qui démarre avant l'existence du `.env`.
Les scripts de `scripts/ci/*.sh` et le workflow appellent les commandes.
