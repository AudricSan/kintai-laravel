# Contribuer à Kintai

🌐 [English](../../CONTRIBUTING.md) · **Français** · [日本語](CONTRIBUTING.ja.md)

Merci de votre intérêt pour ce projet !

## Avant de commencer

Kintai est publié sous licence **GNU Affero General Public License v3.0** (AGPL-3.0).
En contribuant, vous acceptez que vos contributions soient publiées sous les mêmes conditions.

## Comment contribuer

### Signaler un bug

Ouvrez une issue avec le template **Bug Report**. Incluez :
- Version de PHP et OS
- Étapes de reproduction
- Comportement attendu vs. observé
- Logs pertinents de `storage/logs/`

### Proposer une fonctionnalité

Ouvrez une issue avec le template **Feature Request**. Expliquez le cas d'usage et en quoi elle s'inscrit dans le périmètre du projet — voir [Vision produit (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Vision-produit) pour la direction produit.

### Soumettre une Pull Request

1. Forkez le dépôt
2. Créez une branche : `git checkout -b feat/ma-fonctionnalite` ou `fix/mon-bug`
3. Faites vos changements en suivant les conventions ci-dessous
4. Lancez les tests : `./vendor/bin/phpunit`
5. Ouvrez une pull request contre la branche `develop` (la branche d'intégration par défaut pour le travail au quotidien — la fusionner n'y publie **pas** de release). `main`, `alpha` et `beta` sont des branches de canal de release protégées : les fusionner y crée automatiquement un tag et publie une Release GitHub, elles ne sont donc mises à jour que délibérément, en promouvant `develop` vers l'avant (voir [releasing.fr.md](releasing.fr.md))

## Conventions de code

- PHP 8.3+, types stricts (`declare(strict_types=1)`) dans chaque fichier
- Racine du namespace : `kintai\` → `src/`
- Contrôleurs : `final class`, injection par constructeur, signature `method(Request $request): Response` — les paramètres de route sont lus via `$request->param('nom')`, jamais en argument de méthode
- La persistance passe par des interfaces de repository (`src/Core/Repositories/*Interface.php`) liées dans `RepositoryServiceProvider` — contrôleurs et services ne touchent jamais directement aux modèles Eloquent
- Les erreurs HTTP utilisent la hiérarchie d'exceptions de `src/Core/Exceptions/`
- Nouvelles tables : ajoutez une seule migration Eloquent dans `database/migrations/php/` — elle doit fonctionner pour SQLite et MySQL (pas de fichiers par driver)
- SQLite ici n'applique pas `ON DELETE CASCADE` — supprimez explicitement les lignes dépendantes dans le code du repository
- Les commentaires dans le code sont écrits en français ; tout le reste (messages de commit, descriptions de PR, documentation) en anglais
- Aucune dépendance framework externe au-delà d'`illuminate/database` (Eloquent, utilisé uniquement comme ORM)
- Jamais de `style="..."` inline dans les vues — étendez un module CSS sous `public/assets/css/src/`
- Aucun handler d'événement inline (`onclick=`, `onchange=`, `onsubmit=`, `oninput=`…), aucun lien `javascript:` ni `<script>` sans nonce dans les vues ou composants — la Content-Security-Policy (`script-src 'self' 'nonce-…'`) les bloque en silence. Utilisez `data-on-click`/`data-on-change`/`data-args`, `data-submit-on-change`, `data-confirm`, `data-stop-propagation` (`public/assets/js/modules/csp-actions.js`) et `<script nonce="<?= csp_nonce() ?>">` ; `NoInlineScriptGuardTest` échoue en cas de violation. Auteurs de bundles : voir the wiki page [Creating a Bundle](https://github.com/AudricSan/Kintai/wiki/Creating-a-Bundle) (« Content Security Policy »)
- Les barres de filtres s'appliquent instantanément — pas de bouton "Filtrer". Les champs texte soumettent le formulaire avec un debounce sur `input` (voir le câblage générique dans `public/assets/js/app.js` qui cible `form.filter-bar`/`form.shifts-filters`), les champs `select`/date soumettent sur `onchange`. Cela s'applique à tous les filtres, y compris les champs de recherche par nom/texte

## Lancer les tests

```bash
composer install
./vendor/bin/phpunit
```

La CI exécute aussi un test d'installation neuve (job `install-smoke`, `scripts/ci/install-smoke-test.sh`) : le vrai installateur web sur une base SQLite vide, puis une connexion avec le compte créé. Il écrit dans le dépôt, donc il refuse de tourner hors CI (`CI=true`) ou là où une installation existe déjà — en local, uniquement dans un clone jetable.

Un second job, `provision-smoke` (`scripts/ci/provision-smoke-test.sh`), fait de même pour l'installation en ligne de commande, `scripts/provision.php`, y compris ses refus (entrée invalide, instance déjà installée, compte existant). Mêmes garde-fous.

Chaque fonctionnalité nouvelle ou modifiée doit être accompagnée de tests PHPUnit dans `tests/Unit/` (et `tests/Integration/` le cas échéant).

## Langues de la documentation

Le README, le CHANGELOG, CONTRIBUTING et SECURITY sont disponibles en anglais, français (`.fr.md`) et japonais (`.ja.md`) — les traductions se trouvent dans `docs/i18n/` — et le [wiki](https://github.com/AudricSan/Kintai/wiki) (la documentation détaillée) l'est dans les mêmes trois langues. L'anglais fait foi — si vous modifiez un document anglais, mettre à jour les traductions est apprécié mais pas requis pour qu'une PR soit acceptée. `php scripts/check-translations.php` signale les traductions manquantes ou obsolètes (non bloquant, exécuté en CI à chaque push).

## Par où commencer

- [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Architecture) — comment le framework est construit
- [Stratégie base de données (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Strategie-base-de-donnees) — modèles, repositories, migrations
- [CHANGELOG.fr.md](CHANGELOG.fr.md) — ce qui a été fait récemment
