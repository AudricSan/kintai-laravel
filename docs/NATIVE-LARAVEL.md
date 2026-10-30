# Passage à Laravel natif

Objectif : supprimer toute la couche maison (routeur, conteneur, requête/réponse, session, CSRF, moteur de vues,
migrateur, traducteur, authentification…) pour obtenir une application Laravel standard. **Aucune rétrocompatibilité** :
les instances déjà en production sont réinstallées (ou importées par `WebInstaller::import()`), et le contrat de bundle
repart en 1.0.0.

Règle de travail : chaque étape laisse la suite PHPUnit au niveau de la référence ci-dessous, et fait l'objet d'un commit.

## Référence

- 2765 tests, 1 échec d'environnement : `KintaiScriptCommandTest` (avertissement `openssl` de la ligne de commande sous
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
| Flash, anciennes valeurs, erreurs de validation : `session()->flash()`, `withErrors()` / `withInput()`, `$errors` (layouts) | Natif |
| Routage lisible : `Route::bind('store'|'employee')` (`App\Routing\RouteSegments`), `SubstituteBindings`, 301 des anciens liens | Natif |
| Limitation de débit : `Illuminate\Cache\RateLimiter` (connexion, formulaires sensibles, API) | Natif |
| E-mail : mailer de Laravel (Symfony Mailer), `config/mail.php` standard (`MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_*`) | Natif |
| Environnement : `.env` chargé par Laravel seul (plus de `kintai_load_env_file`, `.env.local`, `.env.ovh`) | Natif |
| Erreurs HTTP : `App\Exceptions\HttpException` étend l'`HttpException` de Symfony (celle de `abort()`) | Natif |

## Choix assumés (conservés)

- **Repositories** : 33 interfaces (`app/Repositories`, `kintai\Contract\Repositories` pour une partie) au-dessus d'Eloquent.
  Les supprimer obligerait à réécrire tous les services (qui manipulent des tableaux) et leurs tests ; les interfaces
  sont aussi le contrat des bundles. Une conversion domaine par domaine reste possible sur demande.
- **`App\Services\Log`** : journal d'activité métier (table `activity_log`, canaux audit/access/business, ressource, IP,
  utilisateur), pas la journalisation technique de Laravel (`storage/logs`), qui sert aux erreurs.
- **`CronRunner`** : registre des tâches (Core et bundles) appelées par le planificateur de Laravel (`routes/console.php`,
  `config/schedule.php`) et par `/cron/run/{tâche}` avec jeton, pour les hébergements qui ne savent appeler qu'une URL.
- **Messages `?success=` / `?error=`** : codes dans l'URL traduits par `Flash` (35 vues, 139 redirections) ; les textes
  libres passent par la session. À convertir en `with('status', …)` si l'on veut la même mécanique partout.
- **`base_url()`, `route_url()`, `kintai_normalize_script_name()`** : glue de sous-dossier (XAMPP, OVH mutualisé) ; la
  racine web peut être `public/` sur un serveur dédié.

## Reste à faire

1. **Bundles** : publier les releases, mettre à jour leurs tests et leur `composer.json`, puis le registre
   `KintaiBundle/registry.json`.
2. **Anciennes bases** : une base d'avant ces migrations a un journal `migrations` sans colonne `batch` ; l'importation
   (`WebInstaller::import()`) le remplace. Une base de développement existante doit être importée ou réinstallée, et
   son `.env` doit recevoir `APP_KEY=` puis `php artisan key:generate`.
3. **Variables d'e-mail** : `MAIL_DRIVER` devient `MAIL_MAILER` (`smtp` ou `sendmail`) et `MAIL_ENCRYPTION` devient
   `MAIL_SCHEME` (`smtps` = TLS direct, `smtp` = STARTTLS). Un `.env` existant doit être mis à jour.