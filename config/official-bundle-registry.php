<?php

declare(strict_types=1);

/**
 * Registry de bundles officiel Kintai, seedé par défaut dans bundle_registries
 * (voir 2026_09_18_000001_seed_official_bundle_registry.php). Un seul
 * emplacement à mettre à jour le jour où le dépôt du registry officiel change
 * d'URL — la table reste la source de vérité une fois seedée, ce fichier ne
 * sert qu'à l'insertion initiale.
 */
return [
    'name' => 'Registry officiel Kintai',
    'url'  => 'https://raw.githubusercontent.com/AudricSan/KintaiBundle/main/registry.json',
];
