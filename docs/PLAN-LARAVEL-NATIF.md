# Plan : Kintai, application Laravel native

Feuille de route de la reconstruction. `NATIVE-LARAVEL.md` reste l'inventaire de l'existant. Branche de travail :
`laravel-native`.

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
   bundles, chacun package Composer avec son service provider auto-découvert. Détails à définir après le Cœur : on
   reconstruit d'abord le corps de l'application.
3. **Interface : Blade + Livewire.** Blade reste la base (les 136 vues se transposent directement). Livewire 4 remplace
   le JavaScript maison (~4800 lignes) là où l'écran est interactif : planning, pointage, formulaires dynamiques,
   filtres. Pas d'Inertia : il faudrait réécrire toutes les vues en Vue/React, et chaque bundle devrait embarquer son
   build JS, ce qui complique beaucoup les packages.
4. **Contrat Cœur ↔ bundles.** Deux sens : le bundle *donne* au Cœur par des registres (widgets, menu, permissions,
   types de notifications, sections du profil, exports…), remplis dans le `boot()` de son ServiceProvider ; le bundle
   *demande* au Cœur par une API publique (modèles, interfaces de services injectées par le conteneur, événements
   émis par le Cœur). Le Cœur ne connaît jamais un bundle par son nom et fonctionne avec des registres vides. Le
   contrat (interfaces, événements, registres) vit dans un package `kintai/contracts` versionné en semver ; un bundle
   déclare `"kintai/contracts": "^1.0"` et Composer refuse un bundle incompatible. Chaque point d'extension est créé
   **avec le domaine du Cœur qui l'utilise**, et le Cœur s'en sert lui-même pour ses propres widgets, entrées de menu,
   etc.
5. **Laravel 13** (dernière version, 13.40 au 04/11/2026), **PHP 8.3 minimum** (minimum de Laravel 13). Passage à
   Laravel 14 à sa sortie, après livraison.

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
- [ ] Décider des fonctionnalités abandonnées ou remplacées (section 7 de l'inventaire).

### Phase 1 : Squelette
- `laravel new` (Laravel 13, kit de démarrage Livewire) sur la branche `laravel-native`, qui remplace l'ancien code.
- Larastan, Pint, PHPUnit, CI ; `.env` standard ; traductions (fr, ja, en selon l'existant).

### Phase 2 : Schéma et modèles
- Migrations redessinées de zéro, modèles avec relations, `$fillable` explicites, casts, factories, seeders.
- Ordre : `User`, `Role`, `RoleAssignment`, `Store`, `StoreUser`, `ShiftType`, `Shift`, `Availability`, puis le reste.

### Phase 3 : Authentification et autorisation
- Authentification du kit de démarrage (connexion, réinitialisation de mot de passe), rôles via les modèles.
- Gate/policies pour tous les droits, middleware `can:` sur les routes.

### Phase 4 : Cœur, par tranches verticales
- Un domaine à la fois, dans l'ordre de la section 9 de l'inventaire : modèles, `FormRequest`, contrôleurs fins, vues Blade/Livewire, `JsonResource`, tests.
- Réponses dédiées pour CSV, PDF, iCal ; exceptions via `bootstrap/app.php`.

### Phase 5 : Bundles officiels (dépôt `kintai-bundles`)
- Le contrat (`kintai/contracts`) existe déjà, construit en phase 4 avec les domaines du Cœur.
- Les 13 bundles, un par un : migrations, vues, traductions, routes, commandes, tâches planifiées, configuration
  publiable, tests.

### Phase 6 : Installation
- `php artisan kintai:install` (+ page web minimale si nécessaire pour l'hébergement mutualisé).

### Phase 7 : Outil de migration (hors application)
- `tools/legacy-migration/` : lit une base de l'ancienne version, écrit dans le nouveau schéma. Testé sur un jeu de
  données d'exemple, documenté à part.

### Phase 8 : Qualité et livraison
- Revue de sécurité (reprise de l'audit du 31/10/2026), documentation (`README`, `DEPLOIEMENT`), changelog, version
  majeure.

## Définition de « terminé »

- Chaque fonctionnalité de l'inventaire de la phase 0 existe dans la nouvelle version, ou son abandon est noté.
- Aucun conteneur/routeur/session/CSRF/vue/migrateur maison ; aucun helper `kintai_*` ; aucun DAO statique.
- Les 13 bundles s'installent par `composer require` et se découvrent seuls.
- Tests, Larastan et Pint verts ; `php artisan` suffit pour installer, migrer, planifier, tester.
- L'outil de migration vit hors de `app/`.

## Points encore ouverts

1. Bundles (phase 5, après le Cœur) : organisation du dépôt (repartir de `KintaiBundle` existant ou dépôt neuf),
   dépendance aux modèles du Cœur, façon de les tester.
2. Fonctionnalités à abandonner : l'inventaire de la phase 0 servira à les proposer.
