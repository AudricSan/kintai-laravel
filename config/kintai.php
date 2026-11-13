<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fuseau de l'instance
    |--------------------------------------------------------------------------
    |
    | Les horodatages sont stockés en UTC (app.timezone). Ce fuseau sert à saisir
    | et à afficher les heures des shifts.
    |
    */

    'timezone' => env('KINTAI_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Installation
    |--------------------------------------------------------------------------
    |
    | `enforce` : tant que Kintai n'est pas installé, toute page web renvoie vers l'assistant `/install`.
    | `lock` : chemin du fichier verrou posé en fin d'installation (par défaut `storage/app/installed`).
    |
    */

    'install' => [
        'enforce' => (bool) env('KINTAI_INSTALL_ENFORCE', true),
        'lock' => env('KINTAI_INSTALL_LOCK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | Version de Kintai, annoncée par `GET /api/v1/ping`. Constante à mettre à jour à chaque version publiée
    | (composer.json ne porte pas de version).
    |
    */

    'version' => '4.0.0',

    /*
    |--------------------------------------------------------------------------
    | Mise à jour
    |--------------------------------------------------------------------------
    |
    | `repository` : dépôt GitHub (« compte/dépôt ») dont les releases fournissent les mises à jour du Core.
    | `channel` : canal suivi (release : versions stables ; beta : + beta et rc ; alpha : tout).
    | `max_download_bytes` : taille maximale de l'archive téléchargée. `max_entries` et `max_uncompressed_bytes` :
    | nombre d'entrées et taille décompressée maximaux de l'archive. `timeout` : délai (secondes) des appels à GitHub.
    |
    */

    'update' => [
        'repository' => env('KINTAI_UPDATE_REPOSITORY', 'AudricSan/kintai-laravel'),
        'channel' => env('KINTAI_UPDATE_CHANNEL', 'release'),
        'max_download_bytes' => 200 * 1024 * 1024,
        'max_entries' => 50000,
        'max_uncompressed_bytes' => 1024 * 1024 * 1024,
        'timeout' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertes système
    |--------------------------------------------------------------------------
    |
    | `system_alerts_on_visit` : vérifier les alertes système (au plus une fois par heure) quand un Owner charge
    | une page, pour les instances dont le planificateur ne tourne pas. Désactivé par les tests.
    |
    */

    'system_alerts_on_visit' => filter_var(env('KINTAI_SYSTEM_ALERTS_ON_VISIT', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | API v1
    |--------------------------------------------------------------------------
    |
    | `version` : numéro de version du contrat de l'API (préfixe d'URL `/api/v1`).
    | `rate_limit` : requêtes par minute et par jeton (par adresse IP sans jeton).
    | `token_max_days` : durée de vie maximale, et par défaut, d'un jeton d'accès (en jours).
    |
    */

    'api' => [
        'version' => '1',
        'rate_limit' => (int) env('KINTAI_API_RATE_LIMIT', 300),
        'token_max_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | `retention_days` : jours pendant lesquels une notification lue est conservée
    | avant la purge quotidienne (bornée entre 7 et 3650).
    |
    */

    'notifications' => [
        'retention_days' => (int) env('KINTAI_NOTIFICATIONS_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal d'activité
    |--------------------------------------------------------------------------
    |
    | `retention_days` : jours de conservation des entrées avant la purge quotidienne
    | (0 = conservation illimitée).
    |
    */

    'activity' => [
        'retention_days' => (int) env('KINTAI_ACTIVITY_RETENTION_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Push (navigateurs)
    |--------------------------------------------------------------------------
    |
    | Paire de clés VAPID de l'instance, créée par `php artisan kintai:webpush:keys`. Sans clés, le Web Push
    | est désactivé (aucune erreur). `subject` : contact transmis aux services de push (mailto: ou URL https) ;
    | à défaut, l'URL de l'application si elle est en https.
    |
    */

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FCM (application mobile)
    |--------------------------------------------------------------------------
    |
    | Firebase Cloud Messaging, API HTTP v1. `credentials_path` : fichier JSON du compte de service, chemin
    | absolu ou relatif à `storage/`. Tant que `enabled` est faux, rien n'est envoyé.
    |
    */

    'fcm' => [
        'enabled' => filter_var(env('FCM_ENABLED', false), FILTER_VALIDATE_BOOL),
        'project_id' => env('FCM_PROJECT_ID', ''),
        'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sauvegardes
    |--------------------------------------------------------------------------
    |
    | `path` : dossier des archives (hors du dossier public, jamais servi). `max_uncompressed_bytes` : taille
    | décompressée maximale acceptée à la restauration (protège d'une archive piégée). `max_entries` : nombre
    | maximal d'entrées d'une archive.
    |
    */

    'backups' => [
        'path' => storage_path('app/private/backups'),
        'max_uncompressed_bytes' => 2 * 1024 * 1024 * 1024,
        'max_entries' => 50000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation intégrée
    |--------------------------------------------------------------------------
    |
    | `repository` : dépôt GitHub (« compte/dépôt ») dont le wiki est synchronisé par `kintai:docs:sync`.
    | `path` : dossier local des pages synchronisées (hors du dossier public).
    |
    */

    'docs' => [
        'repository' => env('KINTAI_DOCS_REPOSITORY', 'AudricSan/Kintai'),
        'path' => storage_path('app/docs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signalement de problème
    |--------------------------------------------------------------------------
    |
    | `repository` : dépôt GitHub qui reçoit les signalements. `github_token` : jeton (portée « public_repo »
    | suffit) pour créer l'issue depuis l'application ; sans lui, l'utilisateur est renvoyé vers le formulaire
    | de création GitHub pré-rempli.
    |
    */

    'support' => [
        'repository' => env('KINTAI_SUPPORT_REPOSITORY', 'AudricSan/Kintai'),
        'github_token' => env('GITHUB_ISSUES_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Licence
    |--------------------------------------------------------------------------
    |
    | Client du LicenseManager (serveur séparé ; l'achat se fait sur son lien public, Kintai ne gère aucun paiement).
    | `api_url` : base de l'API (sans slash final). `api_key` : jeton public du produit Kintai envoyé en `Authorization: Bearer` (capacité `register`, fait pour être public : le secret est la clé de licence de chaque client ; surchargeable par `KINTAI_LICENSE_API_KEY`).
    | `public_key_b64` : clé publique RSA du serveur (PEM encodé en base64), qui vérifie la signature du jeton de licence.
    | `product` : produit attendu dans le jeton. `issuer` : émetteur attendu (vide = non contrôlé). `buy_url` : page d'achat
    | (vide = `{base de api_url}/buy/kintai`). `timeout` : délai (secondes) des appels. `free_limits` : limites du palier
    | gratuit (clé => entier), à définir.
    |
    */

    'license' => [
        'api_url' => env('KINTAI_LICENSE_API_URL', 'https://licence.audricrosier.be/api/v1'),
        'api_key' => env('KINTAI_LICENSE_API_KEY', 'lm_JNd0s3ENKxv8ABkYYAU2PyPiVTtonxI8eBzmfQeN685a5508'),
        'public_key_b64' => 'LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0NCk1JSUJJakFOQmdrcWhraUc5dzBCQVFFRkFBT0NBUThBTUlJQkNnS0NBUUVBcC9NY00wV0d2SERiTWVnS2FUZUcNCjJsMHJJOC9KSStGcGtVQlQ2VE55V0NTUjFPQk1GMTZoODBrRW1ReXgzTWwwVGJNcTJCb215ZmpCdDhDMVV4ZWQNCmlCSGVQVUpQN2o0N1dYb3htV1BzVE1DRXRsM2FqRVB3ME5NYmdLTnpmUzhnUW5nZ0RIaUpScEdicHVXWVhXVGwNCmxBRThuRVZvcnZ1eFFiWnZhT1gzZEgzNXVTN1p4SGdlY21vM1YwZmZsTG84eVBrRHlGbnZ1ejRxY3lDR3laOGoNCm84NDM5UU5vS0oxU3RNN3JOR1NVNjdqK0dpSHN5QzY1akNyUUdJNi9pQlo0ME1haFE3TDQwL3A0UjBSdTRwMkcNCjhJbnRTenpocUFxWTVhZXJmK0VxWkJSanhLMnNCd25IcURBWnMxWXVKNkNmQ1lOTGJlNEtQSXlqb1d6dkFNZTYNCmdRSURBUUFCDQotLS0tLUVORCBQVUJMSUMgS0VZLS0tLS0NCg==',
        'product' => 'kintai',
        'issuer' => env('KINTAI_LICENSE_ISSUER'),
        'buy_url' => env('KINTAI_LICENSE_BUY_URL'),
        'timeout' => 8,
        // Palier gratuit (sans licence valable) : plafonds à la création (null = illimité) et bundles accordés (`*` = tous).
        'free_limits' => ['max_stores' => 1, 'max_employees' => 15],
        'free_bundles' => ['timeoff', 'timeclock', 'shift-swap', 'team-directory'],
    ],

];
