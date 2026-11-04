# Kintai

Kintai est une application web de planning et de gestion du personnel.

> **Branche `reconstruction`** : reconstruction de Kintai sur un squelette Laravel 13 neuf, en Blade et Livewire 4
> (kit de démarrage Livewire, authentification intégrée de Laravel). Le code de l'ancienne version n'est pas repris
> sur cette branche ; il sert de référence fonctionnelle.

- Plan et décisions : [docs/PLAN-LARAVEL-NATIF.md](docs/PLAN-LARAVEL-NATIF.md)
- Spécification fonctionnelle : [docs/INVENTAIRE-FONCTIONNEL.md](docs/INVENTAIRE-FONCTIONNEL.md)
- Documents de l'ancienne version : [docs/archive/](docs/archive/)

## Prérequis

- PHP 8.3 ou supérieur
- Composer
- Node.js et npm

## Installation de développement

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

La base de données par défaut est SQLite (`database/database.sqlite`, à créer si absente).

## Qualité

```bash
composer test       # tests (php artisan test)
composer analyse    # analyse statique (PHPStan / Larastan, niveau 6)
composer lint       # vérification du style (Pint, preset laravel)
vendor/bin/pint     # corrige le style
```

## Licence

Voir [LICENSE](LICENSE).
