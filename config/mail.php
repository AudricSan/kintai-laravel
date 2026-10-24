<?php

/**
 * Configuration de l'envoi de mails.
 *
 * Copier les variables suivantes dans votre environnement (.env, OVH env vars,
 * ou variables du système) pour configurer le transport sur la production.
 *
 * ─── Driver 'native' (défaut, développement) ─────────────────────────────────
 *   MAIL_DRIVER=native
 *   MAIL_FROM_ADDRESS=noreply@votre-domaine.com
 *   MAIL_FROM_NAME=Kintai
 *
 * ─── Driver 'smtp' (recommandé en production OVH) ────────────────────────────
 *   MAIL_DRIVER=smtp
 *   MAIL_FROM_ADDRESS=noreply@votre-domaine.com
 *   MAIL_FROM_NAME=Kintai
 *   MAIL_HOST=pro1.mail.ovh.net             ← serveur OVH (ou tout SMTP, sans préfixe ssl://)
 *   MAIL_PORT=465
 *   MAIL_USERNAME=noreply@votre-domaine.com
 *   MAIL_PASSWORD=mot_de_passe_smtp
 *   MAIL_ENCRYPTION=ssl                    ← 'ssl' (port 465) ou 'tls' (port 587)
 *
 * ─── Cron OVH ────────────────────────────────────────────────────────────────
 *   CRON_SECRET=une_chaine_secrete_aleatoire
 *   Endpoint : GET https://votresite.com/cron/auto-validate?token=SECRET
 */

declare(strict_types=1);

return [
    'driver' => env('MAIL_DRIVER', 'native'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'Kintai'),
    ],

    'smtp' => [
        'host'       => env('MAIL_HOST',       'pro1.mail.ovh.net'),
        'port'       => (int) env('MAIL_PORT',  465),
        'username'   => env('MAIL_USERNAME',    ''),
        'password'   => env('MAIL_PASSWORD',    ''),
        'encryption' => env('MAIL_ENCRYPTION',  'ssl'),
    ],
];
