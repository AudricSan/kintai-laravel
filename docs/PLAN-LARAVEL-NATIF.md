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
6. **Licence conservée.** La refonte du serveur de licence est un chantier séparé, plus tard. En dernière phase : un
   serveur d'auto-déploiement de Kintai, conçu avec le serveur de licence.
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
      `bundle_update_available`, `license_quota`, détecteurs réseau à enregistrer dans `App\System\SystemAlerts`) et
      réinitialisation d'usine (réinstallation).
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
      Core pour agir sur un compte). 13 sur 13 portés.

### Phase 6 : Installation et mise à jour
- `php artisan kintai:install` et assistant d'installation web.
- Mise à jour web depuis les releases GitHub (canaux release/beta/alpha), et CI qui construit l'archive de release.
- Licence (client) sur le serveur de licence actuel.

### Phase 7 : Outil de migration (hors application)
- `tools/legacy-migration/` : lit une base de l'ancienne version, écrit dans le nouveau schéma. Testé sur un jeu de
  données d'exemple, documenté à part.

### Phase 8 : Qualité et livraison
- Revue de sécurité (reprise de l'[audit du 31/10/2026](archive/Audit_Kintai_Migration_Laravel_Securite_2026-10-31.md)), documentation (`README`, [`DEPLOIEMENT`](archive/DEPLOIEMENT.md)), changelog, version
  majeure.
- CSP complète (`script-src` avec nonce). La tranche 9a n'émet qu'une CSP de base (`frame-ancestors 'self'`,
  `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, voir `App\Http\Middleware\SecurityHeaders`) : Alpine en
  build standard exige `unsafe-eval`, il faut donc passer aux builds CSP de Livewire/Alpine (ou accepter `unsafe-eval`)
  avant de restreindre les scripts. Les en-têtes déjà posés par une réponse (service worker, iCal, API) priment.

### Phase 9 : Serveur d'auto-déploiement (après livraison)
- Conçu avec la refonte du serveur de licence (chantier séparé).

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
