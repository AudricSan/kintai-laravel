<?php

declare(strict_types=1);

/**
 * Questions de l'assistant de configuration qui recommandent des bundles (étape « Bundles »), dans l'ordre de priorité
 * métier : quand le plan ne permet pas tous les bundles choisis, les premiers de cette liste sont installés d'abord.
 *
 * - `id`      : identifiant stable (réponses du formulaire) ;
 * - `bundles` : slugs recommandés si l'on répond « oui » ;
 * - `icon`    : pictogramme de la carte ;
 * - `theme`   : regroupement des cartes (planning, requests, communication, reports, hr).
 *
 * Le texte vient des traductions : `setup_q_{id}` (la question) et `setup_q_{id}_hint` (ce que cela apporte).
 *
 * Un bundle tiers peut ajouter la sienne sans toucher à ce fichier : son entrée de registry porte un champ
 * `setup_question` ({"text": {"fr": "…", "en": "…", "ja": "…"}, "icon": "…", "theme": "…"}) ; sa question vient après
 * celles-ci. Un bundle qu'aucune question ne mentionne reste proposé dans la liste complète.
 */
return [
    ['id' => 'timeclock',   'bundles' => ['timeclock'],                       'icon' => '⏱',  'theme' => 'planning'],
    ['id' => 'timeoff',     'bundles' => ['timeoff'],                         'icon' => '🏖',  'theme' => 'requests'],
    ['id' => 'exchange',    'bundles' => ['shift-swap', 'shift-claim'],       'icon' => '🔁', 'theme' => 'requests'],
    ['id' => 'daily',       'bundles' => ['daily-report'],                    'icon' => '📝', 'theme' => 'reports'],
    ['id' => 'planning',    'bundles' => ['planning-check'],                  'icon' => '📋', 'theme' => 'reports'],
    ['id' => 'messaging',   'bundles' => ['messaging'],                       'icon' => '💬', 'theme' => 'communication'],
    ['id' => 'notebook',    'bundles' => ['notebook'],                        'icon' => '📒', 'theme' => 'communication'],
    ['id' => 'directory',   'bundles' => ['team-directory'],                  'icon' => '👥', 'theme' => 'communication'],
    ['id' => 'photos',      'bundles' => ['store-photos'],                    'icon' => '📷', 'theme' => 'reports'],
    ['id' => 'hr',          'bundles' => ['hiring-report', 'resignation-report', 'salary-report'], 'icon' => '🧾', 'theme' => 'hr'],
];
