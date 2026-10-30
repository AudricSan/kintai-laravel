# Kintai — {{Label}}

Bundle `{{slug}}` du monorepo [Kintai](https://github.com/AudricSan/kintai-laravel) (`bundles/{{slug}}/`). Les vues (`Views/`) sont des gabarits Blade.

## Développement

```bash
vendor/bin/phpunit --testsuite Bundles      # depuis la racine de Kintai
```

Le test `tests/ContractTest.php` impose le contrat de bundle : schéma de `bundle.json`, classes et fonctions du Core
autorisées, traductions (préfixe `{{prefix}}_`, parité fr/en/ja).

## Règles du contrat (rappel)

- Classes : seulement `kintai\Contract\…`. Fonctions globales : celles de `app/Contract/functions.php` et les helpers de Laravel.
- Traductions : clés préfixées `{{prefix}}_` (plus `bundle_{{prefix}}`, `notif_*`, `audit_*`, `cron_job_*`) ; surcharge d'une clé
  du Core uniquement via `lang/overrides/{locale}.json`.
- Interface : aucun style inline ni gestionnaire `on…=` (CSP à nonce) ; composants `k-*` du design system.
- Releases : tag `{{slug}}-vX.Y.Z` posé par `.github/workflows/bundles-release.yml` à chaque push sur `alpha`, `beta` ou `main`.
