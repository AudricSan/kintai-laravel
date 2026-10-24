<?php

declare(strict_types=1);

/**
 * Surface du contrat de bundle (kintai\Contract\Contract::VERSION) : seuls ces noms peuvent être utilisés par un
 * bundle. Chacun est un alias stable vers la classe actuelle du Core, déclaré au démarrage par aliases.php, ce qui
 * permet au Core de réorganiser son code sans casser les bundles.
 *
 * Statut « stable » : garanti pour toute la version majeure du contrat.
 * Statut « provisoire » : exposé pour la transition, remplacé lors d'une étape suivante du plan (voir la description) ;
 * il ne disparaîtra qu'avec une nouvelle version majeure du contrat.
 *
 * ContractSurfaceTest vérifie que chaque nom est déclaré et désigne bien sa classe.
 *
 * @return array<class-string, array{target: class-string, status: 'stable'|'provisoire', role: string}>
 */
return [
    'kintai\\Contract\\Bundle' => ['target' => 'kintai\\Core\\BundleContract\\Bundle', 'status' => 'stable', 'role' => 'Classe de base de tout bundle (point d\'entrée).'],
    'kintai\\Contract\\BundleManifest' => ['target' => 'kintai\\Core\\BundleContract\\BundleManifest', 'status' => 'stable', 'role' => 'bundle.json analysé.'],
    'kintai\\Contract\\Container' => ['target' => 'kintai\\Core\\Container', 'status' => 'stable', 'role' => 'Conteneur d\'injection (enregistrement des liaisons du bundle).'],
    'kintai\\Contract\\Cron\\CronJob' => ['target' => 'kintai\\Core\\Cron\\CronJobInterface', 'status' => 'stable', 'role' => 'Tâche planifiée déclarée par Bundle::cronJobs() (contrat 1.8).'],
    'kintai\\Contract\\Http\\Request' => ['target' => 'kintai\\Core\\Request', 'status' => 'stable', 'role' => 'Requête HTTP.'],
    'kintai\\Contract\\Http\\Response' => ['target' => 'kintai\\Core\\Response', 'status' => 'stable', 'role' => 'Réponse HTTP.'],
    'kintai\\Contract\\Http\\Router' => ['target' => 'kintai\\Core\\Router', 'status' => 'stable', 'role' => 'Déclaration des routes (routes.php).'],
    'kintai\\Contract\\Http\\Paginator' => ['target' => 'kintai\\Core\\Api\\Paginator', 'status' => 'stable', 'role' => 'Pagination des listes d\'API.'],
    'kintai\\Contract\\Http\\Middleware\\AuthMiddleware' => ['target' => 'kintai\\Core\\Middleware\\AuthMiddleware', 'status' => 'stable', 'role' => 'Session web requise.'],
    'kintai\\Contract\\Http\\Middleware\\PermissionMiddleware' => ['target' => 'kintai\\Core\\Middleware\\PermissionMiddleware', 'status' => 'stable', 'role' => 'Permission web requise.'],
    'kintai\\Contract\\Http\\Middleware\\ApiAuthMiddleware' => ['target' => 'kintai\\Core\\Middleware\\ApiAuthMiddleware', 'status' => 'stable', 'role' => 'Jeton API requis.'],
    'kintai\\Contract\\Http\\Middleware\\ApiPermissionMiddleware' => ['target' => 'kintai\\Core\\Middleware\\ApiPermissionMiddleware', 'status' => 'stable', 'role' => 'Permission API requise.'],
    'kintai\\Contract\\Http\\Concerns\\HasBaseUrl' => ['target' => 'kintai\\UI\\Controller\\Web\\HasBaseUrl', 'status' => 'stable', 'role' => 'Préfixe des URL (installation en sous-dossier).'],
    'kintai\\Contract\\Http\\Concerns\\HasAdminAccess' => ['target' => 'kintai\\UI\\Controller\\Web\\HasAdminAccess', 'status' => 'provisoire', 'role' => 'Portée magasins du gestionnaire ; remplacé par CurrentUser/Gate (étape 2).'],
    'kintai\\Contract\\Http\\Concerns\\HasStoreFeatureCheck' => ['target' => 'kintai\\UI\\Controller\\Web\\HasStoreFeatureCheck', 'status' => 'provisoire', 'role' => 'Fonctionnalité activée pour un magasin ; remplacé par le point d\'extension storeFeatures (étape 2).'],
    'kintai\\Contract\\Http\\Concerns\\HasStaffReportCrud' => ['target' => 'kintai\\UI\\Controller\\Web\\Staff\\HasStaffReportCrud', 'status' => 'provisoire', 'role' => 'CRUD partagé des rapports RH ; rejoindra les bundles concernés (étape 4).'],
    'kintai\\Contract\\Exceptions\\NotFoundException' => ['target' => 'kintai\\Core\\Exceptions\\NotFoundException', 'status' => 'stable', 'role' => '404.'],
    'kintai\\Contract\\Exceptions\\ForbiddenException' => ['target' => 'kintai\\Core\\Exceptions\\ForbiddenException', 'status' => 'stable', 'role' => '403.'],
    'kintai\\Contract\\Exceptions\\ValidationException' => ['target' => 'kintai\\Core\\Exceptions\\ValidationException', 'status' => 'stable', 'role' => '422 avec erreurs par champ.'],
    'kintai\\Contract\\Exceptions\\ConflictException' => ['target' => 'kintai\\Core\\Exceptions\\ConflictException', 'status' => 'stable', 'role' => '409.'],
    'kintai\\Contract\\Auth\\PermissionService' => ['target' => 'kintai\\Core\\Auth\\PermissionService', 'status' => 'stable', 'role' => 'Vérification des permissions et portées.'],
    'kintai\\Contract\\Auth\\AuthService' => ['target' => 'kintai\\Core\\Auth\\AuthService', 'status' => 'provisoire', 'role' => 'Utilisateur de la session ; remplacé par CurrentUser (étape 2).'],
    'kintai\\Contract\\Auth\\UserAnonymizer' => ['target' => 'kintai\\Core\\Auth\\UserAnonymizer', 'status' => 'stable', 'role' => 'Suppression d\'un compte : anonymisation (contrat 1.21).'],
    'kintai\\Contract\\Data\\UserRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\UserRepositoryInterface', 'status' => 'stable', 'role' => 'Comptes.'],
    'kintai\\Contract\\Data\\StoreRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\StoreRepositoryInterface', 'status' => 'stable', 'role' => 'Magasins.'],
    'kintai\\Contract\\Data\\StoreUserRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\StoreUserRepositoryInterface', 'status' => 'stable', 'role' => 'Adhésions aux magasins.'],
    'kintai\\Contract\\Data\\ShiftRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\ShiftRepositoryInterface', 'status' => 'stable', 'role' => 'Shifts.'],
    'kintai\\Contract\\Data\\ShiftTypeRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\ShiftTypeRepositoryInterface', 'status' => 'stable', 'role' => 'Types de shift.'],
    'kintai\\Contract\\Data\\UserShiftTypeRateRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\UserShiftTypeRateRepositoryInterface', 'status' => 'stable', 'role' => 'Taux horaires individuels.'],
    'kintai\\Contract\\Data\\AppSettingsRepositoryInterface' => ['target' => 'kintai\\Core\\Repositories\\AppSettingsRepositoryInterface', 'status' => 'stable', 'role' => 'Paramètres de l\'instance.'],
    'kintai\\Contract\\Database\\Migration' => ['target' => 'kintai\\Core\\Database\\Migration', 'status' => 'stable', 'role' => 'Classe de base des migrations du bundle.'],
    'kintai\\Contract\\Services\\AuditLogger' => ['target' => 'kintai\\Core\\Services\\AuditLogger', 'status' => 'stable', 'role' => 'Journal d\'activité.'],
    'kintai\\Contract\\Services\\NotificationService' => ['target' => 'kintai\\Core\\Services\\NotificationService', 'status' => 'stable', 'role' => 'Notifications (et push).'],
    'kintai\\Contract\\Services\\TranslationService' => ['target' => 'kintai\\Core\\Services\\TranslationService', 'status' => 'stable', 'role' => 'Traductions.'],
    'kintai\\Contract\\Services\\ImageCompressionService' => ['target' => 'kintai\\Core\\Services\\ImageCompressionService', 'status' => 'stable', 'role' => 'Compression d\'images.'],
    'kintai\\Contract\\Services\\Mailer' => ['target' => 'kintai\\Core\\Mail\\MailerService', 'status' => 'stable', 'role' => 'Envoi d\'e-mails par le transport configuré de l\'instance : send(destinataires, sujet, HTML, pièces jointes) (contrat 1.15).'],
    'kintai\\Contract\\Services\\PdfCjkFontResolver' => ['target' => 'kintai\\Core\\Services\\PdfCjkFontResolver', 'status' => 'stable', 'role' => 'Police japonaise des PDF (mPDF) : PdfCjkFontResolver::applyTo($config) (contrat 1.16).'],
    'kintai\\Contract\\Services\\Sales' => ['target' => 'kintai\\Core\\Extension\\Sales', 'status' => 'stable', 'role' => 'Ventes journalières des magasins, lues auprès des fournisseurs (contrat 1.14).'],
    'kintai\\Contract\\Services\\ShiftWageCalculator' => ['target' => 'kintai\\Core\\Services\\ShiftWageCalculator', 'status' => 'stable', 'role' => 'Coût d\'un shift (source unique du calcul de salaire).'],
    'kintai\\Contract\\Services\\ShiftServiceInterface' => ['target' => 'kintai\\Core\\Services\\ShiftServiceInterface', 'status' => 'provisoire', 'role' => 'Création de shifts ; remplacé par une écriture ciblée (étape 3).'],
    'kintai\\Contract\\Services\\StoreStatsServiceInterface' => ['target' => 'kintai\\Core\\Services\\StoreStatsServiceInterface', 'status' => 'provisoire', 'role' => 'Statistiques magasin ; remplacé par des fournisseurs (étape 3).'],
    'kintai\\Contract\\View\\ViewRenderer' => ['target' => 'kintai\\UI\\ViewRenderer', 'status' => 'stable', 'role' => 'Rendu des vues du bundle.'],
    'kintai\\Contract\\View\\Components\\Badge' => ['target' => 'kintai\\UI\\Components\\Badge', 'status' => 'stable', 'role' => 'Composant badge.'],
    'kintai\\Contract\\View\\Components\\Button' => ['target' => 'kintai\\UI\\Components\\Button', 'status' => 'stable', 'role' => 'Composant bouton.'],
    'kintai\\Contract\\View\\Components\\Card' => ['target' => 'kintai\\UI\\Components\\Card', 'status' => 'stable', 'role' => 'Composant carte.'],
    'kintai\\Contract\\View\\Components\\EmptyState' => ['target' => 'kintai\\UI\\Components\\EmptyState', 'status' => 'stable', 'role' => 'Composant état vide.'],
    'kintai\\Contract\\View\\Components\\FilterBar' => ['target' => 'kintai\\UI\\Components\\FilterBar', 'status' => 'stable', 'role' => 'Barre de filtres instantanés.'],
    'kintai\\Contract\\View\\Components\\Flash' => ['target' => 'kintai\\UI\\Components\\Flash', 'status' => 'stable', 'role' => 'Message flash.'],
    'kintai\\Contract\\View\\Components\\Form' => ['target' => 'kintai\\UI\\Components\\Form', 'status' => 'stable', 'role' => 'Formulaire (CSRF, méthode).'],
    'kintai\\Contract\\View\\Components\\Modal' => ['target' => 'kintai\\UI\\Components\\Modal', 'status' => 'stable', 'role' => 'Fenêtre modale.'],
    'kintai\\Contract\\View\\Components\\Pagination' => ['target' => 'kintai\\UI\\Components\\Pagination', 'status' => 'stable', 'role' => 'Pagination.'],
    'kintai\\Contract\\View\\Components\\Table' => ['target' => 'kintai\\UI\\Components\\Table', 'status' => 'stable', 'role' => 'Tableau.'],
    'kintai\\Contract\\View\\Components\\UserPicker' => ['name' => 'Sélecteur d\'employés', 'target' => 'kintai\\UI\\Components\\UserPicker', 'status' => 'stable', 'role' => 'Liste d\'employés à cocher avec « tout sélectionner » et compteur, pour choisir des personnes dans un formulaire (destinataires d\'un envoi, valideurs…). UserPicker::make(nom du champ, candidats)->selected(ids)->legend()->hint()->render() ; le formulaire reçoit un tableau d\'identifiants de compte (contrat 1.29).'],
    'kintai\\Contract\\Services\\UserSelection' => ['name' => 'Sélection d\'employés d\'un magasin', 'target' => 'kintai\\Core\\Services\\UserSelection', 'status' => 'stable', 'role' => 'Fournit les employés actifs d\'un magasin à proposer (candidates(), filtrable par permission), relit une sélection enregistrée en écartant les comptes inactifs, retirés ou sans adresse (resolve()) et valide une sélection reçue : inconnu, plafond, au moins un (validate()). On enregistre des identifiants de compte, jamais des adresses (contrat 1.29).'],
];
