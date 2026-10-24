<?php

declare(strict_types=1);

/**
 * Fonctions globales que les bundles ont le droit d'appeler (kintai\Contract\Contract::VERSION). Comme surface.php pour
 * les classes : tout ce qui n'est pas listé ici sous « declared » est INTERNE au Core et peut être renommé ou supprimé
 * sans préavis ; ContractUsageScanner::functionViolations() le signale dans la CI des bundles.
 *
 * - `since`  : version du contrat qui a déclaré la fonction ;
 * - `status` : « stable » (garantie pour toute la version majeure) ou « provisoire » (à remplacer, voir `role`) ;
 * - `params` : noms des paramètres, dans l'ordre ; `returns` : type de retour. Vérifiés par ContractFunctionsTest.
 *
 * `internal` liste les autres fonctions globales du Core : un nouveau helper doit être rangé dans l'une ou l'autre
 * (ContractFunctionsTest échoue sinon), ce qui force une décision explicite.
 *
 * @return array{
 *   declared: array<string, array{since: string, status: 'stable'|'provisoire', params: list<string>, returns: string, role: string}>,
 *   internal: list<string>
 * }
 */
return [
    'declared' => [
        '__'                  => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['key', 'replace'], 'returns' => 'string', 'role' => 'Traduction dans la langue courante ; renvoie la clé si elle est absente. Les paramètres remplacent « :nom ».'],
        'csrf_token'          => ['since' => '1.24.0', 'status' => 'stable', 'params' => [], 'returns' => 'string', 'role' => 'Jeton CSRF de la session.'],
        'csrf_field'          => ['since' => '1.24.0', 'status' => 'stable', 'params' => [], 'returns' => 'string', 'role' => 'Champ caché HTML du jeton CSRF, à placer dans chaque formulaire POST.'],
        'csp_nonce'           => ['since' => '1.24.0', 'status' => 'stable', 'params' => [], 'returns' => 'string', 'role' => 'Nonce de la requête pour un <script nonce="…"> inévitable.'],
        'style_class'         => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['declarations'], 'returns' => 'string', 'role' => 'Classe CSS portant des déclarations connues au rendu seulement (sans attribut style).'],
        'inline_styles'       => ['since' => '1.24.0', 'status' => 'stable', 'params' => [], 'returns' => 'string', 'role' => 'Bloc de styles à nonce regroupant les classes de style_class().'],
        'route_url'           => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['name', 'params'], 'returns' => 'string', 'role' => 'URL d\'une route nommée, préfixe de sous-dossier compris.'],
        'base_url'            => ['since' => '1.24.0', 'status' => 'stable', 'params' => [], 'returns' => 'string', 'role' => 'Préfixe des URL de l\'installation (sous-dossier éventuel).'],
        'back_url'            => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['fallback'], 'returns' => 'string', 'role' => 'Destination du lien « Retour » : page précédente (Referer), sinon le repli.'],
        'safe_redirect_path'  => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['path', 'fallback'], 'returns' => 'string', 'role' => 'Chemin interne sûr pour une redirection (refuse les URL externes), sinon le repli.'],
        'store_segment'       => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['storeId'], 'returns' => 'string', 'role' => 'Segment d\'URL lisible d\'un magasin.'],
        'employee_segment'    => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['userId'], 'returns' => 'string', 'role' => 'Segment d\'URL lisible d\'un employé.'],
        'route_segment'       => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['type', 'id'], 'returns' => 'string', 'role' => 'Segment d\'URL lisible d\'un objet (« store », « employee »).'],
        'bundle_asset'        => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['slug', 'path'], 'returns' => '?string', 'role' => 'URL publique d\'un fichier de public/ du bundle (CSS, JS, images).'],
        'translation_or'      => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['fallback', 'key'], 'returns' => 'string', 'role' => 'Traduction de la clé si elle existe, sinon le texte de repli.'],
        'user_locale'         => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['user'], 'returns' => 'string', 'role' => 'Langue d\'un utilisateur (profil), sinon celle de l\'instance.'],
        'month_label'         => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['date'], 'returns' => 'string', 'role' => 'Libellé de mois traduit (« octobre 2026 »).'],
        'date_label'          => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['date'], 'returns' => 'string', 'role' => 'Date lisible traduite.'],
        'format_currency'     => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['amount', 'currency', 'jpySymbolStyle'], 'returns' => 'string', 'role' => 'Montant formaté dans la devise.'],
        'currency_symbol'     => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['currency', 'jpySymbolStyle'], 'returns' => 'string', 'role' => 'Symbole d\'une devise.'],
        'store_currency_style' => ['since' => '1.24.0', 'status' => 'stable', 'params' => ['store'], 'returns' => 'string', 'role' => 'Style du symbole yen configuré pour un magasin.'],
        'bundle_asset_path'   => ['since' => '1.24.0', 'status' => 'provisoire', 'params' => ['slug', 'path'], 'returns' => '?string', 'role' => 'Chemin disque d\'un fichier public/ du bundle (PDF, images embarquées) ; sera remplacé par un service de ressources.'],
        'storage_path'        => ['since' => '1.24.0', 'status' => 'provisoire', 'params' => ['path'], 'returns' => 'string', 'role' => 'Chemin du dossier de stockage de l\'instance ; sera remplacé par un service de fichiers du bundle.'],
    ],
    'internal' => [
        'env', 'kintai_installed_via_database', 'kintai_load_env_file', 'kintai_normalize_script_name',
        'bundle_enabled', 'feat_bundle', 'hash_token', 'render_markdown', 'asset_version', 'mascot_path', 'mascot_active',
        'notification_type_catalog', 'notification_type_label', 'notification_type_icon', 'notification_body', 'notification_badge_label',
        'instance_locale', 'audit_action_label', 'safe_color', 'wcag_contrast_color', 'shift_day_reference',
    ],
];
