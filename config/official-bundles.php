<?php

declare(strict_types=1);

/**
 * Slugs des bundles officiellement développés et maintenus par le projet
 * Kintai (voir Bundle::getName() de chaque classe pour la correspondance).
 *
 * Tout bundle découvert par BundleDiscoveryService (legacy dans app/Bundles/,
 * ou installé dynamiquement dans storage/bundles/) dont le slug n'apparaît
 * PAS ici est un bundle tiers — l'écran /admin/bundles/market affiche alors
 * un avertissement "non maintenu par le projet principal". Ce fichier ne fait
 * confiance à aucune auto-déclaration du bundle lui-même : seule cette liste
 * versionnée avec le core fait foi.
 * */
return [
    'daily-report',
    'messaging',
    'store-photos',
    'timeoff',
    'shift-swap',
    'shift-claim',
    'resignation-report',
    'salary-report',
    'hiring-report',
    'timeclock',
    'team-directory',
    'notebook',
    'planning-check',
];
