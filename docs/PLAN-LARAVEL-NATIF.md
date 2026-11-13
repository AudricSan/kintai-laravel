# Plan : Kintai, application Laravel native

Feuille de route de la reconstruction. [`archive/NATIVE-LARAVEL.md`](archive/NATIVE-LARAVEL.md) reste l'inventaire de l'existant. Branche de travail :
`reconstruction` (créée depuis `laravel-native`, qui garde l'ancien code intact).

## Objectif

Kintai devient une application Laravel standard : n'importe quel développeur Laravel s'y retrouve sans connaître l'ancien
framework maison. Plus aucune couche maison, plus aucun pont de compatibilité.

## Décisions (04/11/2026)

1. **Reconstruction, pas refonte progressive.** On repart d'un squelette Laravel neuf et on reconstruit Kintai domaine
   par domaine. Le dépôt de production `Kintai` (`AudricSan/Kintai`, branche `develop`, dossier `../Kintai`, en
   lecture seule) sert de **spécification** :
   on y lit les écrans, routes, règles métier et tests, on n'en copie pas l'infrastructure. Les calculs métier purs
   (salaires, heures, contrôles de planning…) peuvent être repris après relecture, retypés sur les modèles Eloquent.
   Raison : la refonte progressive impose de garder ancien et nouveau compatibles à chaque commit (l'essai du 04/11 sur
   `findById` a cassé 197 tests à cause d'une soixantaine de signatures `array`). Reconstruire en tranches verticales
   complètes évite ces ponts et va plus vite.
2. **Bundles : un dépôt monorepo des bundles officiels** (`kintai-bundles`), distinct de l'application, contenant les 13
   bundles, chacun package Composer avec son service provider auto-découvert. Détails à définir après le Core : on
   reconstruit d'abord le Core de l'application.
3. **Interface : Blade + Livewire.** Blade reste la base (les 136 vues se transposent directement). Livewire 4 remplace
   le JavaScript maison (~4800 lignes) là où l'écran est interactif : planning, pointage, formulaires dynamiques,
   filtres. Pas d'Inertia : il faudrait réécrire toutes les vues en Vue/React, et chaque bundle devrait embarquer son
   build JS, ce qui complique beaucoup les packages.
4. **Contrat Core ↔ bundles.** Deux sens : le bundle *donne* au Core par des registres (widgets, menu, permissions,
   types de notifications, sections du profil, exports…), remplis dans le `boot()` de son ServiceProvider ; le bundle
   *demande* au Core par une API publique (modèles, interfaces de services injectées par le conteneur, événements
   émis par le Core). Le Core ne connaît jamais un bundle par son nom et fonctionne avec des registres vides. Le
   contrat (interfaces, événements, registres) vit dans un package `kintai/contracts` versionné en semver ; un bundle
   déclare `"kintai/contracts": "^1.0"` et Composer refuse un bundle incompatible. Chaque point d'extension est créé
   **avec le domaine du Core qui l'utilise**, et le Core s'en sert lui-même pour ses propres widgets, entrées de menu,
   etc. *État réel (05/11/2026, étape 5a)* : le package `packages/contracts` existe (`Kintai\Contracts`, version 1.0.0,
   [README](../packages/contracts/README.md)). Il contient une interface par registre (menu, widgets, sections du profil,
   demandes en attente, fonctionnalités de magasin, permissions, types de notification, actions du journal, jeux
   d'export, alertes système), `Settings` et `ActivityRecorder`, les objets valeur, `TimeOffChecker` /
   `TimeOffProvider` (ils prennent des identifiants), `ShiftReplacer`, les 24 événements du Core (identifiants
   seulement) et la base `BundleServiceProvider`. Les implémentations restent dans `app/` (`Default*Registry`,
   `AppSettings`, `ActivityLogger`, `ReplaceShift`…) et sont liées aux interfaces dans `AppServiceProvider` ; le contrat
   n'a aucune dépendance vers `App\` (test `tests/Unit/Contracts`). L'activation est gérée par `App\Bundles\BundleManager`
   (réglage `bundles.enabled`, aucun bundle activé à l'installation) et l'écran Owner « Bundles ».
5. **Installation et mise à jour depuis le navigateur, conservées.** Des clients sont en hébergement mutualisé sans
   terminal : assistant d'installation web et mise à jour depuis les releases GitHub (archive construite par la CI,
   `vendor/` inclus, migrations lancées par `Artisan::call`), en plus des commandes `php artisan`. Même besoin pour les
   bundles.
6. **Licence.** Le serveur de licence (LicenseManager) a été refondu en Laravel natif (Filament, Cashier, Sanctum, JWT RS256,
   branche `refonte-native`). Kintai s'y connecte par `app/License/` (clé produit en jeton Bearer). L'achat se fait dans le
   Manager (`/buy/{produit}/{palier}`), Kintai ne gère aucun paiement. En dernière phase : un serveur d'auto-déploiement de
   Kintai.
7. **Fonctionnalités** : édition des traductions dans l'interface abandonnée (fichiers `lang/` versionnés) ;
   signalement de problème vers GitHub et documentation synchronisée depuis le wiki conservés.
8. **API v1 = contrat public pour une future application mobile** qui se branche sur n'importe quelle instance par son
   adresse : API versionnée et stable, jetons Sanctum, point d'entrée de découverte (`GET /api/v1/ping` : nom,
   version, fonctionnalités de l'instance), push mobile (FCM) conservé.
9. **Laravel 13** (dernière version, 13.40 au 04/11/2026), **PHP 8.3 minimum** (minimum de Laravel 13). Passage à
   Laravel 14 à sa sortie, après livraison.

## Core et bundles

Règle : le Core contient ce que toute installation utilise et la plateforme sur laquelle les bundles se branchent ;
un bundle contient ce qui est optionnel, propre à un usage ou lié à un service extérieur.

**Core** : identité, profil, utilisateurs, rôles et droits ; magasins et membres ; types de shift et taux ; planning
(shifts, conflits, garde-fous) ; calcul des coûts (`ShiftWageCalculator`) ; disponibilités ; tableau de bord,
navigation, notifications (base, mail, Web Push, FCM), journal d'activité ; exports (mécanisme + jeux de données du
Core) ; flux iCal ; API v1 ; administration (réglages, sauvegardes, maintenance, installation et mise à jour web,
licence, documentation, signalement, tâches planifiées) ; page « demandes en attente » (vide sans bundle).

**Bundles existants** (13) : timeclock, timeoff, shift-swap, shift-claim, messaging, notebook, team-directory,
store-photos, daily-report, planning-check, salary-report, hiring-report, resignation-report.

**À sortir du Core vers des bundles (validé le 04/11/2026)** :
- **Synchronisation Google Agenda** → bundle `google-calendar` : service extérieur, OAuth, file de synchronisation.
  Porté (06/11/2026), voir le suivi de la phase 5.
- **Import Excel de planning** → bundle `planning-import` : algorithme lié au format de fichier des clients.
- **Statistiques, rentabilité, rapports par employé** (`StoreStatsService`, widgets financier et absentéisme) →
  bundle `analytics` ; le Core garde le calcul des coûts et l'aperçu du coût dans le formulaire de shift.
- **Déductions** (réglages par magasin, `subject_to_deductions`) → bundle `salary-report`, qui fait la paie.

Le Core ne dépend jamais d'un bundle : là où il en a besoin (congés pour le garde-fou du planning), il définit une
interface dans `kintai/contracts` avec une implémentation vide par défaut, que le bundle remplace.

## Principes

1. **Version majeure, sans compatibilité interne.** Schéma libre, colonnes et alias historiques abandonnés.
2. **Import d'anciennes instances : hors de l'application.** Script autonome (`tools/legacy-migration/`), migration
   définitive et à sens unique. Aucun lien avec `app/`.
3. **Idiomes Laravel uniquement** : Eloquent (relations, scopes, casts, factories), `FormRequest`, policies/Gate,
   `JsonResource`, événements, files, `Schedule`, gestionnaire d'exceptions standard, tests `RefreshDatabase`.
4. **Qualité dès le premier commit** : Larastan niveau 6 sans baseline, Pint, CI ; chaque tranche livrée avec ses tests
   de fonctionnalité (HTTP), portés depuis l'ancienne suite quand ils décrivent un comportement.

## Phases

### Phase 0 : Cadrage
- [x] Base de référence verte de l'ancienne version : 3611 tests, 10808 assertions.
- [x] Référence : `../Kintai`, branche `develop` (production, dernières mises à jour).
- [x] Inventaire fonctionnel (spécification) : `docs/INVENTAIRE-FONCTIONNEL.md` (04/11/2026, zones non lues listées).
- [x] Fonctionnalités abandonnées ou conservées : décisions 5 à 8 ci-dessus.

### Phase 1 : Squelette
- `laravel new` (Laravel 13, kit de démarrage Livewire) sur la branche `reconstruction`, qui remplace l'ancien code.
- Larastan, Pint, PHPUnit, CI ; `.env` standard ; traductions (fr, ja, en selon l'existant).

### Phase 2 : Schéma et modèles
- Schéma : `docs/SCHEMA-CIBLE.md`. Migrations, modèles (relations, casts, `$fillable`), factories, seeder des rôles.
- Périmètre : identité, rôles, magasins, planning ; les autres tables viennent avec leur tranche de la phase 4.

### Phase 3 : Authentification et autorisation
- Authentification du kit de démarrage (connexion, réinitialisation de mot de passe), rôles via les modèles.
- Gate/policies pour tous les droits, middleware `can:` sur les routes.
- Inscription publique désactivée (instances privées : les comptes sont créés par les gérants). Double authentification
  et passkeys du kit conservées, en option dans le profil (validé le 04/11/2026).
- Droits maison (rôles, permissions par rôle, attributions globales ou par magasin), sans `spatie/laravel-permission`.
- Rendre `first_name` et `last_name` obligatoires (facultatifs en phase 2 tant que l'inscription du kit existe).
- Conception (04/11/2026) :
  - Connexion Fortify (`authenticateUsing`) par e-mail, ou par code employé + code magasin (membre du magasin),
    même message d'erreur et même durée de réponse dans tous les cas de refus ; limitation par identifiant et par IP.
  - Pas de vérification d'e-mail (comptes créés par les gérants). Mot de passe : 8 caractères minimum (règle de
    l'ancienne version).
  - Mot de passe à changer (`users.must_change_password`, posé à la création par un gérant ou si le mot de passe saisi
    à la connexion est trop court) : rappel uniquement, comme avant (bandeau, et fenêtre une fois par connexion),
    levé dès que le mot de passe change.
  - Changement de mot de passe : autres sessions déconnectées (`AuthenticateSession`), cookies « rester connecté »
    invalidés ; compte désactivé : session coupée à la requête suivante.
  - Droits : registre des permissions (le Core et les bundles y déclarent leurs catégories) ; `Authorizer` (Owner,
    attributions globales ou par magasin, `all_stores`) branché par `Gate::before`, donc `can()`, `@can` et `can:`
    fonctionnent avec un magasin ou un modèle portant `store_id` ; plafond d'attribution des rôles ; seul un Owner
    agit sur un Owner ; il reste toujours un Owner actif.
  - Suppression d'un compte = anonymisation (données personnelles effacées, droits et sessions retirés, historique
    conservé), événement `UserAnonymized` pour les bundles.

### Phase 4 : Core, par tranches verticales
- Un domaine à la fois, dans l'ordre de la section 9 de l'inventaire : modèles, `FormRequest`, contrôleurs fins, vues Blade/Livewire, `JsonResource`, tests.
- Réponses dédiées pour CSV, PDF, iCal ; exceptions via `bootstrap/app.php`.
- Avancement (05/11/2026) :
  - [x] 1. Identité : profil, employés, rôles (Owner seulement), traduction française.
  - [x] 2. Magasins et membres, types de shift.
  - [x] 3. Shifts (garde-fous, remplacement, conflits), calcul des coûts, disponibilités ; écrans du planning (liste,
    calendrier, frise avec création rapide et glisser-déposer, impression, vues employé).
  - [x] 4. Tableau de bord (registre des widgets, dispositions personnelles et par rôle) et menu (registre, préférences,
    barre mobile) ; demandes en attente (registre, vide sans bundle).
  - [x] 5. Notifications (base, e-mail, Web Push, FCM, préférences, heures silencieuses), journal d'activité.
  - [x] 6. Exports (registre de jeux de données, CSV/Excel/PDF/ICS), flux iCal (jeton haché), exports personnels.
  - [x] 7. API v1 (Sanctum, documentation `docs/API-V1.md`) ; un mot de passe de moins de 8 caractères ne reçoit pas de
    jeton (`403 password_change_required`, comme la production ; validé le 05/11/2026).
  - [x] 8. Synchronisation Google : rien dans le Core, le bundle `google-calendar` se branche sur les événements des
    shifts, les sections du profil, les types de notifications et le planificateur de Laravel.
  - [x] 9. Administration, en trois tranches :
    - [x] 9a. Réglages de l'instance en base (service typé, secrets chiffrés), écran de l'Owner, mode maintenance, en-têtes
      de sécurité.
    - [x] 9b. Sauvegardes en PHP pur (SQLite et MySQL/MariaDB), restauration, sauvegarde automatique avec rotation,
      réinitialisation des données avec sauvegarde préalable.
    - [x] 9c. Alertes système de l'Owner (registre de détecteurs, déduplication par état, commande horaire et passe à la
      visite d'un Owner), tâches planifiées sans terminal (`/cron/run` à jeton haché, écran « Tâches planifiées »),
      documentation intégrée synchronisée depuis le wiki GitHub, signalement de problème vers GitHub.
    - Reporté à la phase 6 : alertes de mise à jour du Core, des bundles et de licence (`core_update_available`,
      `bundle_update_available`, `license_quota`, détecteurs réseau à enregistrer dans `App\System\SystemAlerts`).
    - Abandonné : mascotte et journal d'accès (le journal d'activité couvre l'audit).

### Phase 5 : Bundles officiels
- Organisation (validée le 05/11/2026, remplace le dépôt `kintai-bundles` de la décision 2) :
  - Les bundles vivent dans ce dépôt, `packages/bundles/<slug>`, chacun package Composer (`kintai/bundle-<slug>`)
    branché en dépôt local (`path`) ; publiés par tag `<slug>-vX.Y.Z` comme le prévoit déjà `KintaiBundle/registry.json`.
    Séparation en dépôt dédié plus tard si besoin.
  - Le contrat est un package local `packages/contracts` (`kintai/contracts`, namespace `Kintai\Contracts`, semver) :
    registres, objets valeur et interfaces aujourd'hui dans `app/`. Le Core et chaque bundle en dépendent.
  - Installation sans terminal : les archives de release construites par la CI contiennent les bundles officiels dans
    `vendor/` ; l'Owner les active ou désactive dans l'interface, puis par magasin (fonctionnalités). Pas de Composer ni
    de téléchargement de code depuis le web ; un bundle tiers s'installe par `composer require`.
- Étapes :
  - [x] 5a. Extraction du contrat et gestion de l'activation des bundles.
    - `packages/contracts` (`kintai/contracts` 1.0.0, branché en dépôt `path` à `symlink`) : interfaces des registres,
      objets valeur, événements, `ShiftReplacer`, `Contract::VERSION` et `Contract::satisfies()` (contraintes `^`, `~`,
      `1.*`, intervalles), classe `BundleServiceProvider` (identité `slug/name/version/description`, `requiredContract()`,
      aides d'enregistrement gardées par l'activation, migrations toujours chargées, vues/traductions/routes seulement si
      activé, routes préfixées `/<slug>` avec les noms `<slug>.*` et les middleware `web` + `auth`).
    - Core : `App\Bundles\BundleManager` (découverte des `BundleServiceProvider` enregistrés par l'auto-découverte
      Laravel, état dans `bundles.enabled`, refus d'un bundle dont la version majeure du contrat diffère, suppression du
      cache de routes à chaque changement), actions `EnableBundle` / `DisableBundle` (journal `bundle.enabled`,
      `bundle.disabled`), écran Owner « Bundles » (section système), `GET /api/v1/ping` liste `bundle:<slug>`.
    - Un changement d'état s'applique à la requête suivante (les bundles se branchent au démarrage) ; l'écran recharge la
      page. Les routes d'un bundle n'existent que s'il est activé : après `php artisan route:cache`, une activation
      supprime le fichier de cache.
    - Bundle de démonstration `tests/Fixtures/Bundles/DemoBundle` et `packages/bundles/` (voir son
      [README](../packages/bundles/README.md)).
  - [x] 5b et suivantes : les 13 bundles un par un (migrations, vues, traductions, routes, commandes, tâches planifiées,
    configuration publiable, tests).
    - Suivi : portés (`packages/bundles/<slug>`) : notebook, team-directory, timeoff, timeclock, shift-swap, shift-claim,
      messaging, store-photos, daily-report, planning-check, salary-report, **hiring-report** (droits
      `hiring_reports.*` ; rapport créé à `EmployeeCreated` pour chaque magasin de l'employé, supprimé à
      `UserAnonymized`, aperçu et PDF mPDF, aucune donnée personnelle dans le journal), **resignation-report** (droits
      `resignation_reports.*`, rapports supprimés à `UserAnonymized`, aperçu et PDF mPDF ; la désactivation du compte
      à la date de démission, la réactivation et les exports multi-magasins du legacy ne sont pas repris : pas de contrat
      Core pour agir sur un compte ; repris depuis avec le contrat 1.5, voir plus bas). 13 sur 13 portés.
    - Suivi, après les 13 : **planning-import** (import Excel de planning, détection en trois phases, aperçu puis
      confirmation via les actions du Core, alias de noms et réglages de grille par magasin ; droit `shifts.import`
      conservé dans le Core, fichier envoyé non conservé, plus aucun code d'import dans le Core) ; **analytics**
      (statistiques, rentabilité et rapports par employé : pages par magasin et par employé, widgets statistiques,
      financier et absentéisme, export, droits `payroll.view`/`payroll.export` du Core, coûts via `ShiftWageCalculator`).
      Contrat **1.5** (`AccountActivation`, `EmployeeAction`) : resignation-report désactive le compte à la date de départ
      et ajoute « Licencier ». Contrat **1.6** (`DailySalesProvider`, `DailySales`, implémentation vide dans le Core) :
      daily-report fournit les ventes. 16 bundles committés.
    - Contrat **1.7** : `TimeOffStatistics` (timeoff) et `ShiftSwapStatistics` (shift-swap) comblent les écarts
      d'analytics (congés par statut et par type, échanges par employé) ; `StoreAction` et `StoreActionRegistry`
      (actions de ligne de la liste des magasins) portent les liens Statistiques et Rentabilité d'analytics.
    - Retenues de salary-report : retenues personnalisées par magasin (table `salary_report_deductions`, pourcentage du
      brut ou montant fixe par employé payé, instantané `deduction_lines` conservé dans chaque rapport, édition dans
      les réglages du bundle). Vérification navigateur des statistiques, de la liste des magasins et des employés faite.
      Phase 5 terminée ; l'écran de réglages des retenues n'a pas été contrôlé dans le navigateur.
    - Suivi, bundles sortis du Core : **google-calendar** (06/11/2026, `kintai/bundle-google-calendar`, contrat ^1.5) :
      liaison OAuth (code + PKCE, `state` en session à usage unique) de chacun à son compte Google, client HTTP de
      Laravel (`Http::fake()` en test), jeton de rafraîchissement chiffré (cast `encrypted`), réglages de l'instance
      (identifiant et secret client chiffré, page Owner `manage-settings`, URI de redirection tirée de l'URL publique),
      synchronisation Kintai vers Google des shifts (écouteurs `ShiftCreated/Updated/Deleted/Restored/Replaced`) et des
      congés approuvés (`TimeOffProvider::approvedFor`, par magasin), file et réconciliation par la tâche
      `google-calendar:sync` (déclarée par le bundle, chaque minute, aussi exécutée par `/cron/run`), révocation du jeton
      notifiée à l'utilisateur, nettoyage à `UserAnonymized`. Aucun code Google dans le Core ni dans `packages/contracts`.
      Le flux d'abonnement iCal n'est pas repris (le Core a le sien).

### Phase 6 : Installation et mise à jour
- [x] 6a. Installateur : `php artisan kintai:install` et assistant d'installation web (`/install`).
  - Suivi (11/11/2026) : cœur partagé `App\Install\Installer` (prérequis `InstallRequirements`, `.env` créé depuis
    `.env.example` sans jamais écraser un `.env` existant, clé d'application, base SQLite ou MySQL/MariaDB vérifiée avant
    toute écriture, migrations, `RoleSeeder`, premier Owner, verrou `storage/app/installed`) ; un Owner déjà en base
    compte aussi comme installé. Commande : options `--name --email --password --db-*`, questions sinon, `--force` pour
    remplacer la base d'un `.env` déjà réglé, `--local` pour garder APP_ENV=local. Web : middleware `RedirectToInstaller`
    (tout renvoie vers `/install`, session et cache en fichier, `.env` et clé préparés) et `EnsureNotInstalled` (404 une
    fois installé) ; CSRF natif, page Blade autonome en français, pas de limiteur de débit (le cache n'a pas encore de
    base). Réglages `kintai.install.enforce` (désactivé en test) et `kintai.install.lock`.
- [x] 6b. Mise à jour web depuis les releases GitHub (canaux release/beta/alpha) : portage terminé ; la CI qui construit l'archive de release reste à faire (6c).
  - Suivi (11/11/2026) : `App\Update` : `Version` et `UpdateChannel` (semver, pré-versions), `ReleaseClient` (API des releases, liste blanche d'URL, choix par semver), `ReleaseDownloader` (plafond, SHA-256 `.sha256`, jeton jamais envoyé aux téléchargements), `ReleaseArchive` (zip-slip, liens, doublons, chemins interdits `.env` `storage/` `*.sqlite` `bootstrap/cache`, manifeste `kintai-release.json` avec `version`, `min_php`, `min_from_version`), `UpdateApplier` (machine à états `idle > staged > swapped > done`, `failed`, `rolled_back` persistée dans `storage/app/updates/state.json` ; sauvegarde, maintenance, remplacement fichier par fichier avec copie des anciens, fichiers obsolètes via `installed-files.json`, migrations dans une requête neuve, retour arrière idempotent fichiers puis base). Écran Owner `/admin/updates` (Livewire), application et fin par contrôleurs simples hors Livewire (code mélangé après le remplacement), commande `kintai:update` (`--check --channel --yes --rollback --finish`), audit `update.*`, réglage `update.channel`.
  - Format d'archive attendu par la CI (6c) : `kintai-X.Y.Z.zip` + `kintai-X.Y.Z.zip.sha256`, à la racine ou sous un dossier unique, avec `vendor/` ; sans `.env`, sans `storage/` (sauf `.gitkeep`), sans `bootstrap/cache/*.php`, sans `.git`.
  - Limites connues : la somme prouve l'intégrité, pas l'auteur (signature possible en phase 8) ; dépôt privé non pris en charge ; remplacement non testé sur un vrai disque ni contre un vrai GitHub (à vérifier avant livraison).
- [x] 6c. CI de l'archive de release : `.github/workflows/release.yml` (tag `v*`), `tools/release/build.php` et `ReleaseBuilder` (testé par `ReleaseBuilderTest`).
  - Suivi (11/11/2026) : `composer install --no-dev` avec `COMPOSER_MIRROR_PATH_REPOS=1` (copie de `packages/` dans `vendor/`, l'archive refuse les liens), `npm run build`, archive + `.sha256` + `kintai-release.json` (`min_php` lu dans `composer.json`, `--min-from-version` facultatif), contrôle par `ReleaseArchive::inspect` de l'application, puis `gh release create` (pré-release si le tag porte une pré-version). Le tag doit égaler `kintai.version`. Exclus : `.env`, `storage/`, `tests/`, `docs/`, `tools/`, `node_modules/`, `.git*`, cache de bootstrap, SQLite, `public/hot`.
  - À vérifier à la première release réelle : le workflow n'a pas encore tourné sur GitHub.
- [x] 6d. Alerte système `core_update_available` (faite) ; `license_quota` faite (id 8, plafonds de magasins ou d'employés atteints, Owner, push + mail) ; `bundle_update_available` reste à faire (dépend d'une source de versions des bundles).
  - Suivi (11/11/2026) : détecteur `CoreSystemAlerts::coreUpdateAvailable()` (id 1) qui lit `UpdateChecker::cached()` sans réseau ; commande `kintai:update:refresh` planifiée à la minute 50 (avant `kintai:system:alerts`), cache de 90 minutes (`UpdateChecker::REFRESH_CACHE_MINUTES`), erreur réseau journalisée en warning ; type de notification Owner (push + mail).
- [x] 6e. Réinitialisation d'usine (retour à l'assistant d'installation) : action `FactoryReset` (Owner `manage-settings`, mot de passe, phrase `FACTORY RESET`, sauvegarde préalable sous `BackupLock`), section « Réinitialisation d'usine » de l'écran `data-reset` et commande `kintai:factory-reset`.
  - Suivi (11/11/2026) : `migrate:fresh` sans seed, suppression des fichiers téléversés (avatars et dossiers des bundles, couverts par la sauvegarde) et du verrou `storage/app/installed`, cache vidé ; `.env`, `APP_KEY` et les sauvegardes ne sont jamais touchés ; journal d'activité effacé avec la base donc trace dans le log Laravel (acteur, sauvegarde) ; session fermée puis redirection vers `/install`.
- [x] 6f. Licence (client) du LicenseManager : `app/License/` (`LicenseClient`, `LicenseState`, `LicenseStatus`, `LicenseResult`), actions `ActivateLicense`/`ValidateLicense`/`DeactivateLicense` (Owner, `manage-settings`, audit `license.*` sans la clé), écran `admin/license`, commande `kintai:license:validate` (quotidienne, seulement si une clé existe), `firebase/php-jwt`.
  - Suivi : jeton RS256 vérifié à chaque lecture (signature, `exp`, `product=kintai`, `iss` facultatif) ; sans jeton valable, palier gratuit. Hors ligne, droits gardés jusqu'à l'`exp` du dernier jeton (état `grace`) ; refus explicite = droits retirés tout de suite ; injoignable ne révoque jamais. `LicenseState::limit($key): ?int` (null = illimité ; sans licence, `kintai.license.free_limits`). Détecteurs sans réseau `license_problem` (id 3) et `license_expiring` (id 7, ≤ 14 jours). Désactivation d'usine : `FactoryReset` libère le siège en meilleur effort. Clé publique de production fournie par défaut (`kintai.license.public_key_b64`, figée : ni variable d'environnement ni réglage en base ne la remplacent ; jeton lié à l'instance par la revendication `instance_id` lorsqu'elle est présente).
  - Reste : renseigner la clé produit (`KINTAI_LICENSE_API_KEY`) en production ; **contrat des bundles** (packages/contracts, non modifié) pour exposer `LicenseState::limit()` aux bundles ; définir les limites du palier gratuit (`kintai.license.free_limits`) ; `bundle_update_available` ; aucune édition de `license.api_url`/`api_key`/`buy_url` dans l'interface (env ou réglages en base).

### Phase 7 : Outil de migration (hors application)
- `tools/legacy-migration/` : lit une base de l'ancienne version, écrit dans le nouveau schéma. Testé sur un jeu de
  données d'exemple, documenté à part (`tools/legacy-migration/README.md`).
- [x] Tranche 1 : Core (identité, mots de passe, rôles et assignations, magasins, planning, réglages, avatars).
  - Suivi (12/11/2026) : `php tools/legacy-migration/migrate.php --source=... [--target-env] [--timezone] [--dry-run]`, PDO seul, aucune classe de `app/`. Une seule version source (schéma actuel de `develop`, contrôlé table et colonne par colonne) ; source en lecture seule, `database.local.php` lu comme des données (jamais exécuté) ; cible migrée et sans aucun utilisateur, sinon refus (jamais de fusion) ; une transaction (retour arrière complet, avatars copiés supprimés). Hachage bcrypt repris tel quel (prouvé par `Hash::check` et une vraie connexion) ; `must_change_password` pour `0000` et quelques mots de passe faibles connus (la longueur d'un haché est inconnaissable). Rôles rapprochés par `slug`, `scope` devient `all_stores`, portée devient `store_id`. Quarts : heures locales converties en UTC, ouvert = sans employé, `ical_uid` généré. Avatars copiés vers le disque `local` (`storage/app/private/avatars/`), avec contrôle anti-traversal. Jetons iCal non repris (clair dans l'ancien) : chacun régénère son lien. Suite `LegacyMigration` (base source synthétique), Larastan couvre `tools/legacy-migration/src`.
- [ ] Tranche 2 : données des bundles, par sous-tranches ; et reprise des notifications, préférences, journal d'activité, tableaux de bord si souhaitée. Schéma legacy : ancien dépôt `Kintai` (branche `develop`, `database/migrations/php/`).
  - [x] 2a : pointage (`timeclocks`), congés (`timeoff_requests`), reprises (`shift_claims`), échanges (`shift_swap_requests`).
    - Suivi (12/11/2026) : tables de bundle facultatives dans la source (`Source::OPTIONAL`, colonnes contrôlées seulement si la table existe), étape sautée avec note si la table manque côté source ou cible ; ids conservés ; heures locales converties en UTC ; statuts rapprochés par alias (inconnu = ligne ignorée, jamais réactivée) ; échange sans collègue ou quart cible ignoré (la cible l'exige), `accepted_by_id` perdu ; `ical_sequence` des quarts repris dans les séquences de l'échange. `shift_claim_offers` et `timeclock_overtime_alerts` sans équivalent legacy. Tests : `LegacyBundlesMigrationTest` (7).
  - [x] 2b : rapports d'embauche, de démission et journaliers (+ réglages du rapport journalier).
    - Suivi (12/11/2026) : `hiring_reports` et `resignation_reports` repris tels quels (`user_id`/`created_by` à null si absents, `deactivated_at` null) ; `daily_reports` : lignes supprimées (`deleted_at`) ignorées, doublon (magasin, date) = premier id, `approved` devient `validated`, statut inconnu ignoré, `data` et PDF non repris ; réglages lus dans le JSON `stores.daily_report_settings` (valeurs validées, défaut de la cible sinon). **À vérifier sur une vraie base** : les noms des clés du JSON des réglages (seule `cumulative_mode` est confirmée, le code du bundle legacy est dans un dépôt externe). Tests : `LegacyReportsMigrationTest` (9).
  - [x] 2c : fiches de paie (`salary_reports`, `store_deduction_settings` vers `salary_report_settings`, `store_user.subject_to_deductions` vers `salary_report_members`).
    - Suivi (12/11/2026) : aucune valeur « corrigée » ; montant ou compteur illisible/négatif = fiche ignorée avec avertissement ; `target_month` AAAA-MM validé ; doublon (magasin, mois, employé ou global) = plus petit id ; responsable (texte) rapproché seulement si un seul utilisateur porte ce nom exact, sinon null ; `employee_work_hours` (texte libre « Nom: 7.5h » dans l'ancienne version) perdu sauf JSON strict `[{user_id, minutes}]` ; `store_name`/`employee_name` non repris (relus dans la cible) ; `deduction_lines` null ; `salary_report_deductions` sans équivalent legacy. Tests : `LegacySalaryMigrationTest` (16).
  - [x] 2d : photos de magasin (`store_photo_submissions`, `store_photo_images` + fichiers).
    - Suivi (12/11/2026) : `filepath` lu relatif au dossier `uploads` de la source (option `--source-uploads`), mêmes protections que les avatars (anti-traversal, pas de lien symbolique) ; fichier copié tel quel (pas de ré-encodage, aucune classe de `app/`) vers `storage/app/private/store-photos/<32 hex>.jpg|png`, seuls JPEG et PNG repris (type lu dans le contenu, 10 Mo, 40 Mpx) ; fichier absent/illisible/autre type = image ignorée avec avertissement ; soumission supprimée ou sans image reprise ignorée ; `week_label`, rétention, `compressed*`, `mail_sent_at` non repris ; aucune copie en `--dry-run`, fichiers supprimés en cas d'échec. **À vérifier sur une vraie base** : la convention de `filepath` (relatif à `uploads/`) vient de la description du schéma, le code du bundle legacy est dans un dépôt externe. Tests : `LegacyStorePhotosMigrationTest` (8).
  - [x] 2e : calendrier Google (`calendar_connections`, `calendar_event_links`, `calendar_sync_queue`).
    - Suivi (12/11/2026) : jetons **migrés** : `refresh_token` déchiffré (réimplémentation autonome du `SecretBox` legacy : `enc1:`, AES-256-GCM, clé dérivée de `app_settings.instance_secret`) puis rechiffré avec l'`APP_KEY` du `.env` de la cible au format du cast `encrypted` (AES-256-CBC) ; sans `APP_KEY` côté cible, import refusé. Jeton illisible (altéré, autre secret, secret absent, préfixe inconnu, vide) : liaison non reprise avec avertissement (l'employé reconnecte son agenda) ; ni jeton ni secret dans le rapport. Seulement `provider = google`, employé repris, une liaison par employé ; `options` : `calendar_name_mode`/`store_id` vers `name_mode`/`store_id` (défaut `instance`), `reconciled_at` repris ; statut inconnu = `revoked`. Liens et file : seulement ceux des liaisons reprises, `source_id` vers `source_key` (`shift` : id ; `timeoff` : `timeoff-<id>`), autre type ignoré, doublons ignorés, empreinte invalide ignorée. Tests : `LegacyGoogleCalendarMigrationTest` (12). **À vérifier sur une vraie base** : la structure d'`options` vient du code de l'ancienne version, pas d'une base réelle.
  - [x] 2f : import de planning : alias et réglages d'import (`import_aliases`, `store_import_settings`).
    - Suivi (12/11/2026) : schéma confirmé par lecture des migrations de l'ancienne version (`stores.excel_import_settings` supprimée, seule la table `store_import_settings` compte). Tables facultatives côté source et cible, une transaction, `--dry-run`. Réglages : une ligne par magasin (doublon = plus petit id), les 8 entiers doivent être dans 0..65535 et le motif de feuille une regex valable construite comme `GridSettings::regex` (`~...~u`, 255 caractères au plus) ; sinon le réglage n'est pas repris, avec avertissement (valeurs par défaut du bundle, rien d'inventé). Alias : colonne `staff_name` ou, dans une base ancienne, `alias_name` (`staff_name` préféré, `Source::ONE_OF` ; l'une des deux est exigée), nom mis en minuscules (avertissement agrégé), doublon après minuscules = plus petit id, nom vide ou > 255 caractères, magasin ou employé absent = ignoré ; `updated_at` = `created_at`. Tests : `LegacyPlanningImportMigrationTest` (17).
  - [x] 2g : messagerie (`message_threads`, `thread_participants`, `thread_messages`) et annuaire (colonnes facultatives de `users` vers `team_directory_profiles`).
    - Suivi (12/11/2026) : tables facultatives, ids conservés, dates locales converties en UTC (`sent_at` = `created_at`) ; sujet null/vide repris comme `''` (la cible l'accepte), sujet > 255 = fil ignoré ; fil sans magasin/créateur, participant ou message orphelin, message sans texte ou sans date, doublon de participant = ignorés ; fil sans participant ni message repris tel quel ; `type` non repris. Annuaire : colonnes de `users` facultatives, ligne créée seulement si un champ est rempli ou `show_in_directory = 0`, champ trop long ignoré avec avertissement (pas de troncature). Tests : `LegacyMessagingMigrationTest` (6), `LegacyDirectoryMigrationTest` (5).
    - Carnet (`notebook`) et contrôle de planning (`planning-check`) : aucune table legacy dans le dépôt Kintai, rien à migrer, à reconfirmer dans les dépôts externes.

### Phase 8 : Qualité et livraison
- Revue de sécurité du 12/11/2026 (agent `reviewer`, lecture seule) : audit du 31/10 : 8 constats corrigés, 5 partiels, 2 ouverts (SEC-02 mot de passe `0000`, SEC-07 `SESSION_SECURE_COOKIE`). Corrigés : N1 (lien de réinitialisation tiré de Host), N2 (plafond anti-prise de compte, `Authorizer::canTakeOverAccount`), N6 (licence en https). Corrigés ensuite (13/11/2026) : N3 (jeton d'installation requis sur `/install`), N4 (installations concurrentes sérialisées par `flock`), N5 (`/forgot-password` neutre et limité par IP), SEC-07 (cookie `secure` selon le schéma d'`APP_URL`) et `trustHosts()` calé sur `APP_URL`. SEC-02 (mot de passe `0000`) : comportement accepté, le compte migré doit changer son mot de passe (`must_change_password`). Livraison (13/11/2026) : [`DEPLOIEMENT`](DEPLOIEMENT.md) réécrit pour la version native, `README` à jour, version **4.0.0** (`config/kintai.php`, section `[4.0.0]` du changelog ; le tag `v4.0.0` reste à poser, `v1` à `v3` existent déjà). CSP sur les scripts (13/11/2026) : `script-src 'self' 'unsafe-eval' 'nonce-…'` (nonce par requête, repris par Vite, Livewire, Flux et les vues). **Reste** : retirer `'unsafe-eval'` par le build CSP d'Alpine (après la vérification en navigateur), vérification en navigateur (par le responsable).
- Revue de sécurité (reprise de l'[audit du 31/10/2026](archive/Audit_Kintai_Migration_Laravel_Securite_2026-10-31.md)), documentation (`README`, [`DEPLOIEMENT`](archive/DEPLOIEMENT.md)), changelog, version
  majeure.
- CSP complète (`script-src` avec nonce). La tranche 9a n'émet qu'une CSP de base (`frame-ancestors 'self'`,
  `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, voir `App\Http\Middleware\SecurityHeaders`) : Alpine en
  build standard exige `unsafe-eval`, il faut donc passer aux builds CSP de Livewire/Alpine (ou accepter `unsafe-eval`)
  avant de restreindre les scripts. Les en-têtes déjà posés par une réponse (service worker, iCal, API) priment.

### Phase 8b : Licence pilotée par le LicenseManager (14/11/2026)
Le LicenseManager décide seul de la validité ; Kintai ne fait que vérifier le jeton RS256 (clé publique figée) et appliquer les droits.
- **Paliers** (limites par jeton, claim `limits`) : Free 1 magasin / 15 employés / 4 bundles ; Starter 2 / 30 / 6 bundles (Free + `shift-claim`, `messaging`) ; Pro 3 / 50 / 8 bundles ; Business 10 / 200 / tous ; Enterprise illimité / illimité / tous (émis à la main). Bundles Free : `timeoff`, `timeclock`, `shift-swap`, `team-directory` ; Pro ajoute `shift-claim`, `messaging`, `notebook`, `planning-check` ; Business et Enterprise : tous. Prix (JPY, créés dans Stripe le 14/11/2026) : Starter 2 400/mois, 24 000/an, 72 000 à vie ; Pro 4 900/mois, 49 000/an, 147 000 à vie ; Business 14 800/mois, 148 000/an, 444 000 à vie (à vie = 3 ans d'abonnement annuel).
- **Sans licence valide** : les limites du palier Free s'appliquent (`kintai.license.free_limits`), pas de verrouillage.
- **Licence Free d'auto-hébergement** : `POST {api}/register` (`email`, `instance_id`, `instance_label`), réponse `202 {status: "pending_verification"}` ; le Manager envoie un e-mail de confirmation (lien signé, durée limitée) ; après confirmation il crée le client et une licence `free` (palier `free`, sans échéance, 1 activation) et envoie la **clé de licence** par e-mail ; l'Owner la colle dans l'écran Licence de Kintai (flux `/activate` existant). Limité par IP et par e-mail (une licence Free par e-mail), jeton Sanctum du produit à capacité `register`/`activate`/`validate`/`deactivate`, destiné à être **public** (embarqué dans Kintai : le secret est la clé de licence).
- **Jeton** : nouvelle revendication `instance_id` (liaison à l'installation, vérifiée par Kintai) et `bundles` (liste de slugs ou `*`) dans les limites du palier ; `LicenseState::bundles(): ?array` (null = tous) et `allowsBundle($slug)`.
- **Application dans Kintai** : plafonds `max_stores` et `max_employees` à la création (refus clair, jamais de suppression de l'existant) ; bundles hors palier non enregistrés (menu, routes, widgets).
- **Hors périmètre technique** : un code ouvert reste modifiable ; la protection réelle passe par la licence du code (à changer, avis juridique) et, plus tard, par la livraison des mises à jour et des bundles payants depuis le serveur.

### Phase 9 : Serveur d'auto-déploiement (après livraison)
- S'appuie sur le LicenseManager refondu (déjà livré). À concevoir.

## Définition de « terminé »

- Chaque fonctionnalité de l'inventaire de la phase 0 existe dans la nouvelle version, ou son abandon est noté.
- Aucun conteneur/routeur/session/CSRF/vue/migrateur maison ; aucun helper `kintai_*` ; aucun DAO statique.
- Les 13 bundles s'installent par `composer require` et se découvrent seuls.
- Tests, Larastan et Pint verts ; `php artisan` suffit pour installer, migrer, planifier, tester.
- L'outil de migration vit hors de `app/`.

## Points encore ouverts

1. ~~Bundles : organisation et façon de tester.~~ Tranché (05/11/2026) : organisation en phase 5 ; les tests de chaque
   bundle vivent dans `packages/bundles/<slug>/tests` et tournent dans la suite de l'application (testsuite `Bundles` de
   `phpunit.xml`, comprise dans `php artisan test`), sans Testbench : ils étendent `Tests\TestCase` et activent le bundle
   avec `Tests\Concerns\InstallsBundles::enableBundle()`. Raison : les bundles s'appuient sur le Core réel (modèles,
   rôles, registres), un environnement Testbench le simulerait mal et doublerait la configuration.
2. Fonctionnalités à abandonner : l'inventaire de la phase 0 servira à les proposer.
