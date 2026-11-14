# Kintai

Kintai est une application web de planning et de gestion du personnel (Laravel 13, Blade et Livewire 4).

> **Branche `reconstruction`** : reconstruction de Kintai sur un squelette Laravel neuf, avec l'authentification intégrée
> de Laravel. Le code de l'ancienne version n'est pas repris ; il sert de référence fonctionnelle.

- Plan et décisions : [docs/PLAN-LARAVEL-NATIF.md](docs/PLAN-LARAVEL-NATIF.md)
- Spécification fonctionnelle : [docs/INVENTAIRE-FONCTIONNEL.md](docs/INVENTAIRE-FONCTIONNEL.md)
- Schéma cible : [docs/SCHEMA-CIBLE.md](docs/SCHEMA-CIBLE.md) · API : [docs/API-V1.md](docs/API-V1.md)
- Historique : [CHANGELOG.md](CHANGELOG.md) · Sécurité : [SECURITY.md](SECURITY.md) · Contribution : [CONTRIBUTING.md](CONTRIBUTING.md)
- Déploiement et exploitation : [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md)
- Documents de l'ancienne version : [docs/archive/](docs/archive/)

## Prérequis

- PHP 8.3 ou supérieur, Composer
- Node.js et npm (développement seulement : l'archive de release embarque les assets compilés)
- SQLite (par défaut) ou MySQL

## Installation

### Production

Télécharger l'archive `kintai-X.Y.Z.zip` d'une release GitHub (somme SHA-256 et manifeste `kintai-release.json` fournis),
la décompresser sur le serveur, pointer la racine web sur `public/`, puis au choix :

```bash
php artisan kintai:install        # en ligne de commande
```

ou ouvrir `/install` dans le navigateur (hébergement sans terminal). Les deux utilisent le même installeur : contrôle
des prérequis, `.env`, clé, base de données, migrations, rôles, premier Owner.

Une seule ligne de crontab suffit pour toutes les tâches planifiées :

```
* * * * * cd /chemin/vers/kintai && php artisan schedule:run
```

Mise à jour : page *Mises à jour* (Owner) ou `php artisan kintai:update` (canaux release, beta, alpha).

### Développement

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

La base par défaut est SQLite (`database/database.sqlite`, à créer si absente).

## Organisation

- `app/` : le Core (identité, rôles et permissions, magasins, planning, notifications, API, installeur, mise à jour, licence).
- `packages/bundles/<slug>/` : les bundles officiels, des packages Composer découverts par leur service provider ; chacun
  porte ses migrations, vues, traductions, routes et tests.
- `tools/release/` : construction de l'archive de release (CI sur tag `v*`).

## Commandes utiles

```bash
php artisan kintai:install            # installation
php artisan kintai:update             # mise à jour depuis les releases
php artisan kintai:license:validate   # contrôle de la licence
php artisan kintai:factory-reset      # réinitialisation d'usine (Owner)
```

## Qualité

```bash
composer test       # tests (php artisan test), Core et bundles
composer analyse    # analyse statique (PHPStan / Larastan, niveau 6)
composer lint       # vérification du style (Pint, preset laravel)
vendor/bin/pint     # corrige le style
```

## Licence

Voir [LICENSE](LICENSE).
