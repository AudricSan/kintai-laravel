# Kintai — {{Label}}

Bundle pour [Kintai](https://github.com/{{author}}/Kintai) (`{{slug}}`).

## Développement

```bash
composer install
vendor/bin/phpunit
```

Le dépôt doit être cloné à côté de Kintai : `KintaiBundleDev/kintai-bundle-{{slug}}` et `Kintai` (voir `composer.json`).
Le test `tests/ContractTest.php` impose le contrat de bundle : schéma de `bundle.json`, classes et fonctions du Core
autorisées, traductions (préfixe `{{prefix}}_`, parité fr/en/ja).

## Règles du contrat (rappel)

- Classes : seulement `kintai\Contract\…`. Fonctions globales : seulement celles de `src/Contract/functions.php` du Core.
- Traductions : clés préfixées `{{prefix}}_` (plus `bundle_{{prefix}}`, `notif_*`, `audit_*`, `cron_job_*`) ; surcharge d'une clé
  du Core uniquement via `lang/overrides/{locale}.json`.
- Interface : aucun style inline ni gestionnaire `on…=` (CSP à nonce) ; composants `k-*` du design system.
- Documentation complète : wiki de Kintai, section Développeurs.
