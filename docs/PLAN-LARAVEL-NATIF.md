# Plan : Kintai, application Laravel native

Feuille de route de la migration. `NATIVE-LARAVEL.md` reste l'inventaire de l'existant. Branche de travail : `laravel-native`.

## Objectif

Kintai devient une application Laravel standard : n'importe quel développeur Laravel s'y retrouve sans connaître l'ancien
framework maison. Plus aucune couche maison, plus aucun pont de compatibilité.

## Principes

1. **Version majeure, sans compatibilité interne.** Installée sur de nouveaux produits. Le schéma est libre, les colonnes
   et alias historiques disparaissent.
2. **Import d'anciennes instances : hors de l'application.** Script autonome (`tools/legacy-migration/`), migration
   définitive et à sens unique (maison vers Laravel, jamais l'inverse). Aucun lien avec `app/`.
3. **Bundles : de vrais packages Laravel.** Composer, service provider auto-découvert, API standard du framework.
4. **Idiomes Laravel avant tout** : Eloquent (modèles, relations, scopes, casts), `FormRequest`, policies/Gate, ressources
   d'API, événements, files, `Schedule`, tests `RefreshDatabase`.
5. **Filet de sécurité** : la suite PHPUnit (3611 tests, verte au départ) reste verte à chaque commit ; un commit par
   étape ; phpstan sans erreur.

## Phases

### Phase 0 : Cadrage (fait en partie)
- [x] Base de référence verte : 3611 tests, 10808 assertions.
- [x] Travaux en attente commités, branche `laravel-native`.
- [ ] Figer la liste des écarts à Laravel standard (inventaire de `NATIVE-LARAVEL.md`) et la cible de chacun.
- [ ] **Filet de types avant toute conversion de modèle.** phpstan est à `level: 0` : il ne voit pas qu'un modèle passé à
  un paramètre `array` lève une `TypeError` à l'exécution. Essai du 04/11/2026 : faire renvoyer le modèle par
  `findById` de `Store`, `Shift`, `ShiftType`, `UserShiftTypeRate` et `Availability` casse 197 tests (148 erreurs,
  49 échecs), car une soixantaine de signatures `array $store` / `array $shift` (Cœur et bundles) l'attendent. Annulé.
  Donc : monter phpstan (niveau 5 au moins, avec baseline), puis convertir **un modèle à la fois** en retypant les
  signatures (`Store $store` plutôt que `array`) et en passant des clés de tableau aux propriétés du modèle.

### Phase 1 : Modèles Eloquent idiomatiques (Cœur)
- Les finders statiques (`findById`, `findAll`, `findByX`, `put`, `remove`) laissent la place à `find()`, `where()`,
  `create()`, `save()`, `delete()` et à des scopes nommés.
- Relations déclarées (`User` : rôles, magasins ; `Store` : shifts, types ; …) à la place des jointures manuelles.
- `$guarded = []` remplacé par `$fillable` explicites (SEC-14).
- Fin de `RequestMemo` : relations chargées (`with()`), cache Laravel là où c'est utile.
- Ordre : `User`, `Role`, `RoleAssignment`, `Store`, `StoreUser`, `Shift`, `ShiftType`, puis le reste.

### Phase 2 : Autorisation et authentification
- `Gate`/policies pour tous les droits ; middleware `can:` sur les routes. Fin de `PermissionService` maison et de la clé
  tableau `is_admin` (remplacée par `User::isOwner()` / policies).
- `AuthService` supprimé : `Auth::user()`, `Auth::attempt()`, rôles via le modèle.

### Phase 3 : HTTP
- Validation par `FormRequest` (une quarantaine de `ValidationException` manuels).
- Contrôleurs fins (`Route::resource` quand c'est pertinent), ressources d'API Laravel (`JsonResource`).
- Gestionnaire d'exceptions standard (`bootstrap/app.php`) à la place d'`ErrorResponder`.
- Macros de réponse (`csv`, `pdf`, `ical`…) remplacées par des classes de réponse dédiées.
- `base_url()` / `SCRIPT_NAME` : racine web = `public/`, `request()->getBasePath()`.

### Phase 4 : Bundles Laravel natifs (13)
- Chaque bundle devient un package (`composer.json`, `extra.laravel.providers`) : migrations, vues, traductions, routes,
  commandes, tâches planifiées, configuration publiable, via l'API du provider.
- Plus de contrat maison ni d'alias de classes ; modèles Eloquent natifs ; tests par package.
- Activation/désactivation par configuration et par Composer, plus par registre maison.

### Phase 5 : Installation, schéma, nettoyage
- Migrations regroupées et redessinées (colonnes historiques supprimées), seeders propres.
- Installateur : `php artisan` (+ page web minimale optionnelle), `.env` standard.
- Suppression de `bootstrap/boot.php`, `standalone.php`, helpers maison (`kintai_*`), 301 d'anciens liens.

### Phase 6 : Outil de migration (hors application)
- `tools/legacy-migration/` : lit une base et une configuration de l'ancienne version, écrit dans le schéma Laravel.
- Une seule direction, définitive, testée sur un jeu de données d'exemple. Documenté à part.

### Phase 7 : Qualité et livraison
- Revue de sécurité (reprise de l'audit du 31/10/2026), CI, documentation (`README`, `DEPLOIEMENT`), changelog,
  version majeure.

## Définition de « terminé »

- Aucun `kintai_*` helper, aucun conteneur/routeur/session/CSRF/vue/migrateur maison dans `app/`.
- Aucune méthode statique de type DAO renvoyant des tableaux ; plus de `RequestMemo`.
- Les 13 bundles s'installent par `composer require` et se découvrent seuls.
- Tests et phpstan verts ; `php artisan` suffit pour installer, migrer, planifier, tester.
- L'outil de migration vit hors de `app/`.

## Points à trancher avec vous

1. **Reconstruction ou refonte progressive ?** Recommandation : progressive (les 3611 tests servent de filet).
2. **Bundles : un dépôt par bundle ou le monorepo actuel** (`bundles/<slug>/` avec Composer `path`) ? Recommandation :
   monorepo, publication éventuelle plus tard.
3. **Interface** : on garde Blade tel quel, ou on envisage Livewire/Inertia ? Recommandation : Blade, pas de changement
   d'interface dans cette migration.
4. **Version de Laravel visée** et version minimale de PHP.
