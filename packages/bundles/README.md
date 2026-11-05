# Bundles Kintai

Un bundle ajoute une fonctionnalité optionnelle à Kintai (congés, échanges de shifts, messagerie…). Chaque bundle est un
package Composer qui vit dans `packages/bundles/<slug>`, branché au dépôt racine en dépôt local (`path`, voir
`composer.json`), et qui ne dépend que du contrat [`kintai/contracts`](../contracts/README.md) : il se branche sur le
Core sans le modifier, et le Core ne connaît jamais un bundle par son nom.

## Structure attendue

```
packages/bundles/timeoff/
├── composer.json            # kintai/bundle-timeoff, require kintai/contracts ^1.0, provider auto-découvert
├── src/
│   └── TimeoffServiceProvider.php   # étend BundleServiceProvider
├── database/migrations/     # toujours chargées (même désactivé) : désactiver ne supprime aucune donnée
├── resources/views/         # vues, espace de noms = slug : view('timeoff::index')
├── lang/                    # traductions (fr.json, en.json…) : __('Time off'), et trans('timeoff::messages.x')
├── routes/web.php           # préfixe d'URL /timeoff, noms timeoff.*, middleware web + auth
└── tests/                   # tests du bundle, exécutés avec la suite de l'application
```

`composer.json` minimal :

```json
{
    "name": "kintai/bundle-timeoff",
    "description": "Demandes de congés pour Kintai.",
    "type": "library",
    "license": "AGPL-3.0-only",
    "version": "1.0.0",
    "require": {
        "php": "^8.3",
        "kintai/contracts": "^1.0"
    },
    "autoload": {
        "psr-4": { "Kintai\\Bundle\\Timeoff\\": "src/" }
    },
    "extra": {
        "laravel": {
            "providers": ["Kintai\\Bundle\\Timeoff\\TimeoffServiceProvider"]
        }
    }
}
```

Le provider déclare l'identité du bundle et se branche avec les aides de `BundleServiceProvider`, qui ne
s'appliquent que si l'Owner a activé le bundle :

```php
class TimeoffServiceProvider extends BundleServiceProvider
{
    public function slug(): string { return 'timeoff'; }
    public function name(): string { return 'Time off'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Time off requests and approvals.'; }

    protected function bootBundle(): void
    {
        $this->permissions('timeoff', ['view', 'create', 'approve']);
        $this->storeFeature('timeoff', 'Time off');
        $this->navigation(new NavigationItem(/* ... */));
        $this->listen(ShiftCreated::class, CheckTimeOffOnShift::class);
    }
}
```

Le bundle ne touche jamais `app/` : il lit le Core par ses modèles et par les interfaces du contrat
(`ShiftReplacer`, `TimeOffChecker`…), et il l'alimente par les registres du contrat. Un bundle qui remplace une
implémentation par défaut du Core (par exemple `TimeOffChecker`) la lie dans le conteneur seulement s'il est activé
(`if ($this->isEnabled()) { $this->app->bind(...); }`).

## Tests d'un bundle

Les tests d'un bundle vivent dans `packages/bundles/<slug>/tests` et tournent dans la suite de l'application (suite
`Bundles` de `phpunit.xml`, comprise dans `php artisan test`) : ils étendent `Tests\TestCase` et utilisent les outils
de `tests/Concerns` (`CreatesAccess`, `RefreshDatabase`…), sans Testbench. Pour activer le bundle dans un test :

```php
use Tests\Concerns\InstallsBundles;

class TimeoffTest extends TestCase
{
    use InstallsBundles, RefreshDatabase;

    public function test_something(): void
    {
        $this->enableBundle('timeoff'); // active et rejoue le démarrage du provider
        // ...
    }
}
```

Les migrations d'un bundle sont chargées dès l'installation ; les tests qui s'appuient sur ses tables lancent
`$this->artisan('migrate')` après `enableBundle()`. Le bundle de démonstration
`tests/Fixtures/Bundles/DemoBundle` montre le branchement complet.

Un bundle peut aussi avoir un `routes/api.php` (API mobile : `/api/v1/<slug>`, noms `api.v1.<slug>.*`, pile et erreurs de
l'API du Core, contrôleurs étendant `Kintai\Contracts\Api\ApiController`) et déclarer des droits par défaut pour les rôles
`manager` et `employee` (troisième argument de `permissions()`, accordés à la première activation). Voir le
[contrat](../contracts/README.md).

## Installation sans terminal

Les archives de release contiennent les bundles officiels dans `vendor/` : l'Owner les active ou les désactive dans
l'écran « Bundles », puis choisit les fonctionnalités par magasin. Aucun téléchargement de code depuis le web ; un
bundle tiers s'installe par `composer require`.
