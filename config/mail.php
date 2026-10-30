<?php

/**
 * Configuration de l'envoi de mails (format standard de Laravel). Variables d'environnement (.env, variables OVH ou
 * du système) :
 *
 * ─── sendmail (défaut, développement) ────────────────────────────────────────
 *   MAIL_MAILER=sendmail
 *   MAIL_FROM_ADDRESS=noreply@votre-domaine.com
 *   MAIL_FROM_NAME=Kintai
 *   MAIL_SENDMAIL_PATH=/usr/sbin/sendmail -t -i    ← par défaut, le sendmail_path de php.ini
 *
 * ─── smtp (recommandé en production OVH) ─────────────────────────────────────
 *   MAIL_MAILER=smtp
 *   MAIL_FROM_ADDRESS=noreply@votre-domaine.com
 *   MAIL_FROM_NAME=Kintai
 *   MAIL_HOST=pro1.mail.ovh.net             ← serveur OVH (ou tout SMTP)
 *   MAIL_PORT=465
 *   MAIL_USERNAME=noreply@votre-domaine.com
 *   MAIL_PASSWORD=mot_de_passe_smtp
 *   MAIL_SCHEME=smtps                       ← 'smtps' (TLS direct, port 465) ou 'smtp' (STARTTLS, port 587)
 */

declare(strict_types=1);

return [
    'default' => env('MAIL_MAILER', 'sendmail'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme'    => env('MAIL_SCHEME', 'smtps'),
            'host'      => env('MAIL_HOST', ''),
            'port'      => (int) env('MAIL_PORT', 465),
            'username'  => env('MAIL_USERNAME', ''),
            'password'  => env('MAIL_PASSWORD', ''),
            'timeout'   => 30,
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path'      => env('MAIL_SENDMAIL_PATH', ini_get('sendmail_path') ?: '/usr/sbin/sendmail -bs -i'),
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'Kintai'),
    ],
];
