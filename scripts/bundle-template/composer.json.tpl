{
    "name": "{{authorLower}}/kintai-bundle-{{slug}}",
    "description": "{{Label}} pour Kintai — outillage de tests uniquement, jamais utilisé à l'exécution (Kintai charge ce bundle dynamiquement, pas via Composer).",
    "type": "project",
    "license": "AGPL-3.0-only",
    "require-dev": {
        "phpunit/phpunit": "^12.5"
    },
    "autoload-dev": {
        "psr-4": {
            "kintai\\Bundles\\Installed\\{{Studly}}\\": "src/",
            "kintai\\Tests\\Bundles\\{{Studly}}\\": "tests/"
        }
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true,
        "platform": { "php": "8.3.32" }
    }
}
