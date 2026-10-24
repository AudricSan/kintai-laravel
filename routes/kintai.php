<?php

declare(strict_types=1);

use kintai\UI\Controller\Web\AuthController;
use kintai\UI\Controller\Web\CalendarLinkController;
use kintai\UI\Controller\Web\System\CalendarSettingsController;
use kintai\UI\Controller\Web\BundleAssetController;
use kintai\UI\Controller\Web\PasswordResetController;
use kintai\UI\Controller\Web\NotificationController;
use kintai\UI\Controller\Web\CronController;
use kintai\UI\Controller\Web\EmployeeController;
use kintai\UI\Controller\Web\DashboardLayoutController;
use kintai\UI\Controller\Web\DashboardLayoutsController;
use kintai\UI\Controller\Web\HomeController;
use kintai\UI\Controller\Web\IcalController;
use kintai\UI\Controller\Web\ExportController;
use kintai\UI\Controller\Web\ScheduleExportController;
use kintai\UI\Controller\Web\DocsController;
use kintai\UI\Controller\Web\LegalController;
use kintai\UI\Controller\Web\PrivacyController;
use kintai\UI\Controller\Web\PwaController;
use kintai\UI\Controller\Web\StorageFileController;
use kintai\UI\Controller\Web\SupportController;

use kintai\UI\Controller\Web\Requests\AdminRequestsController;
use kintai\UI\Controller\Web\Scheduling\AdminShiftController;
use kintai\UI\Controller\Web\Scheduling\AdminShiftImportController;
use kintai\UI\Controller\Web\Scheduling\AdminShiftTypeController;

use kintai\UI\Controller\Web\Staff\AdminStoreController;
use kintai\UI\Controller\Web\Staff\AdminUserController;

use kintai\UI\Controller\Web\System\ActivityController;
use kintai\UI\Controller\Web\System\AdminController;
use kintai\UI\Controller\Web\System\AdminRoleController;
use kintai\UI\Controller\Web\System\AppResetController;
use kintai\UI\Controller\Web\System\BackupController;
use kintai\UI\Controller\Web\System\BundleMarketController;
use kintai\UI\Controller\Web\System\BundleSettingsController;
use kintai\UI\Controller\Web\System\LanguageController;
use kintai\UI\Controller\Web\System\LicenseController;
use kintai\UI\Controller\Web\System\SetupWizardController;
use kintai\UI\Controller\Web\System\MailTestController;
use kintai\UI\Controller\Web\System\NotificationSettingsController;
use kintai\UI\Controller\Web\System\OwnerSettingsController;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Middleware\OwnerOnlyMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Middleware\LoginThrottleMiddleware;
use kintai\Core\Middleware\RateLimiterMiddleware;
use kintai\UI\Controller\Api\V1\AuthController as ApiAuthController;
use kintai\UI\Controller\Api\V1\UserController as ApiUserController;
use kintai\UI\Controller\Api\V1\StoreController as ApiStoreController;
use kintai\UI\Controller\Api\V1\StoreUserController as ApiStoreUserController;
use kintai\UI\Controller\Api\V1\ShiftTypeController as ApiShiftTypeController;
use kintai\UI\Controller\Api\V1\ShiftController as ApiShiftController;
use kintai\UI\Controller\Api\V1\AvailabilityController as ApiAvailabilityController;
use kintai\UI\Controller\Api\V1\NotificationController as ApiNotificationController;
use kintai\UI\Controller\Api\V1\UserShiftRateController as ApiUserShiftRateController;
use kintai\UI\Controller\Api\V1\ActivityController as ApiActivityController;
use kintai\UI\Controller\Api\V1\IcalTokenController as ApiIcalTokenController;
use kintai\UI\Controller\Api\V1\UserPrefsController as ApiUserPrefsController;
use kintai\UI\Controller\Api\V1\PushTokenController as ApiPushTokenController;
use kintai\UI\Controller\Api\V1\NotificationPreferenceController as ApiNotificationPreferenceController;
use kintai\UI\Controller\Web\NotificationUnsubscribeController;


/** @var \kintai\Core\Router $router */

// =============================================================================
// Routes publiques (sans authentification)
// =============================================================================

// --- CRON ---
$router->get('/cron/run/{job}', [CronController::class, 'run'], name: 'cron.run');

// --- iCal ---
$router->get('/ical/{token}/{store_id}/shifts.ics', [IcalController::class, 'feed'], name: 'ical.feed');

// --- Authentification ---
$router->get('/login',  [AuthController::class, 'showLogin'], name: 'auth.login');
$router->post('/login', [AuthController::class, 'login'], middleware: [LoginThrottleMiddleware::class], name: 'auth.login.post');
$router->post('/logout', [AuthController::class, 'logout'], name: 'auth.logout');

// --- Réinitialisation de mot de passe ---
$router->get('/forgot-password',  [PasswordResetController::class, 'showForgotForm'], name: 'password.forgot');
$router->post('/forgot-password', [PasswordResetController::class, 'sendLink'], middleware: [RateLimiterMiddleware::class], name: 'password.forgot.post');
$router->get('/reset-password/{token}',  [PasswordResetController::class, 'showResetForm'], name: 'password.reset');
$router->post('/reset-password/{token}', [PasswordResetController::class, 'reset'], middleware: [RateLimiterMiddleware::class], name: 'password.reset.post');

// Désabonnement d'un type d'e-mail de notification : lien signé ouvert depuis le client e-mail, sans connexion.
$router->get('/notifications/unsubscribe', [NotificationUnsubscribeController::class, 'show'], middleware: [RateLimiterMiddleware::class], name: 'notifications.unsubscribe');
$router->post('/notifications/unsubscribe', [NotificationUnsubscribeController::class, 'confirm'], middleware: [RateLimiterMiddleware::class], name: 'notifications.unsubscribe.confirm');

// --- PWA ---
$router->get('/manifest.json', [PwaController::class, 'manifest'], name: 'pwa.manifest');
$router->get('/sw.js', [PwaController::class, 'serviceWorker'], name: 'pwa.service_worker');

// --- Confidentialité & pages légales (footer) ---
$router->get('/privacy', [PrivacyController::class, 'show'], name: 'privacy');
$router->get('/legal/mentions', [LegalController::class, 'mentions'], name: 'legal.mentions');
$router->get('/legal/terms', [LegalController::class, 'terms'], name: 'legal.terms');
$router->get('/legal/license', [LegalController::class, 'license'], name: 'legal.license');

// --- Fichiers uploadés (photos de stores, imports) — réservé aux admins/managers ---
$router->get('/storage/{path*}', [StorageFileController::class, 'serve'], middleware: [AuthMiddleware::class, PermissionMiddleware::class], name: 'storage.file', permission: 'public');

// --- Assets statiques d'un bundle actif (CSS/JS) — publics, pas d'authentification ---
$router->get('/bundle-assets/{slug}/{path*}', [BundleAssetController::class, 'serve'], name: 'bundle.asset');

// =============================================================================
// Routes authentifiées (tout utilisateur connecté)
// =============================================================================

$router->group('/profile', function ($r) {
    $r->get('',              [AuthController::class, 'showProfile'],          name: 'profile');
    $r->post('',             [AuthController::class, 'updateProfile'],        name: 'profile.post');
    $r->post('/avatar',      [AuthController::class, 'uploadAvatar'],         name: 'profile.avatar');
    $r->post('/avatar/delete', [AuthController::class, 'removeAvatar'],       name: 'profile.avatar.delete');
    $r->post('/password',    [AuthController::class, 'saveProfilePassword'],  name: 'profile.password');
    $r->post('/notifications', [AuthController::class, 'saveNotificationPreferences'], name: 'profile.notifications');
    // Liaison de l'agenda Google de l'utilisateur (OAuth, Kintai → Google).
    $r->post('/calendar/google/connect',    [CalendarLinkController::class, 'connect'],    name: 'profile.calendar.connect');
    $r->get('/calendar/google/callback',    [CalendarLinkController::class, 'callback'],   name: 'profile.calendar.callback');
    $r->post('/calendar/google/disconnect', [CalendarLinkController::class, 'disconnect'], name: 'profile.calendar.disconnect');
    $r->post('/calendar/google/options',    [CalendarLinkController::class, 'options'],    name: 'profile.calendar.options');
    $r->post('/calendar/google/resync',     [CalendarLinkController::class, 'resync'],     name: 'profile.calendar.resync');
    $r->get('/export',       [AuthController::class, 'exportData'],           name: 'profile.export');
    // Son propre horaire en JSON, CSV ou ICS (fichier ponctuel, contrairement au flux iCal à jeton).
    $r->get('/schedule-export', [ScheduleExportController::class, 'download'], name: 'profile.schedule_export');
    $r->post('/delete',      [AuthController::class, 'deleteAccount'],        name: 'profile.delete');
}, middleware: [AuthMiddleware::class]);

// Photo de profil : servie hors du groupe /profile pour rester consultable
// via l'ID d'un autre utilisateur (annuaire collègues, bundle TeamDirectory).
$router->get('/avatar/{user_id:employee}', [AuthController::class, 'avatar'], middleware: [AuthMiddleware::class], name: 'user.avatar');

// Exports de données (Core et bundles) en JSON, CSV, Excel ou ICS. Les droits sont décidés par jeu de données et par magasin
// dans ExportAccess, pas par une permission de route : chacun peut au moins exporter ses propres données.
$router->get('/exports',          [ExportController::class, 'index'],    middleware: [AuthMiddleware::class], name: 'exports.index');
$router->get('/exports/download', [ExportController::class, 'download'], middleware: [AuthMiddleware::class], name: 'exports.download');

$router->group('/notifications', function ($r) {
    $r->get('',              [NotificationController::class, 'index'],       name: 'notifications.index');
    $r->get('/poll',         [NotificationController::class, 'poll'],        name: 'notifications.poll');
    $r->post('/read-all',    [NotificationController::class, 'markAllRead'], name: 'notifications.read_all');
    $r->post('/delete-all',  [NotificationController::class, 'deleteAll'],   name: 'notifications.delete_all');
    $r->post('/{id}/read',   [NotificationController::class, 'markRead'],    name: 'notifications.read');
    $r->get('/{id}/open',    [NotificationController::class, 'open'],        name: 'notifications.open');
    $r->post('/push-subscribe',   [NotificationController::class, 'pushSubscribe'],   name: 'notifications.push_subscribe');
    $r->post('/push-unsubscribe', [NotificationController::class, 'pushUnsubscribe'], name: 'notifications.push_unsubscribe');
    $r->post('/push-status',      [NotificationController::class, 'pushStatus'],      name: 'notifications.push_status');
}, middleware: [AuthMiddleware::class]);

// --- Support (footer : "signaler un problème" → issue GitHub) ---
$router->post('/support/report-issue', [SupportController::class, 'reportIssue'], middleware: [AuthMiddleware::class, RateLimiterMiddleware::class], name: 'support.report_issue');

// --- Documentation ---
$router->get('/docs', [DocsController::class, 'index'], middleware: [AuthMiddleware::class], name: 'docs.index');
$router->post('/docs/sync', [DocsController::class, 'sync'], middleware: [AuthMiddleware::class, OwnerOnlyMiddleware::class], name: 'docs.sync');
$router->get('/docs/{lang}/{page}', [DocsController::class, 'show'], middleware: [AuthMiddleware::class], name: 'docs.show');

$router->group('/employee', function ($r) {

    // Dashboard
    $r->get('',             [EmployeeController::class, 'dashboard'],    name: 'employee.dashboard');
    $r->post('/dashboard/widgets', [DashboardLayoutController::class, 'widgetsEmployee'], name: 'employee.dashboard.widgets');
    $r->post('/dashboard/layout',  [DashboardLayoutController::class, 'saveEmployee'],  name: 'employee.dashboard.layout');
    $r->post('/dashboard/layouts', [DashboardLayoutsController::class, 'manageEmployee'], name: 'employee.dashboard.layouts');

    // Shifts
    $r->get('/shifts',          [EmployeeController::class, 'shifts'],         name: 'employee.shifts');
    $r->get('/shifts/calendar', [EmployeeController::class, 'shiftsCalendar'], name: 'employee.shifts.calendar');
    $r->get('/shifts/day',      [EmployeeController::class, 'shiftDay'],       name: 'employee.shifts.day');
    $r->get('/shifts/week',     [EmployeeController::class, 'shiftsWeek'],     name: 'employee.shifts.week');

    // Pointage : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeclock/ une fois installé (voir wiki : Architecture)

    // Congés : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeoff/ une fois installé (voir wiki : Architecture)

    // Profil : géré par /profile (AuthController, page unique admin/manager/employé)
    $r->get('/profile',                    [EmployeeController::class, 'profile'],               name: 'employee.profile');
    $r->post('/profile/availability',      [EmployeeController::class, 'saveProfileAvailability'], name: 'employee.profile.availability');

    // Navigation
    $r->get('/nav-settings',  [EmployeeController::class, 'navSettings'],     name: 'employee.nav_settings');
    $r->post('/nav-settings', [EmployeeController::class, 'saveNavSettings'], name: 'employee.nav_settings.save');


    // Échanges de shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-swap/ une fois installé (voir wiki : Architecture)

    // Bourse aux shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-claim/ une fois installé (voir wiki : Architecture)

    // Messagerie : bundle distribué hors monorepo, routes chargées depuis storage/bundles/messaging/ une fois installé (employee.messages*, voir wiki : Architecture)

}, middleware: [AuthMiddleware::class]);

// --- Changement de vue / langue ---
$router->post('/switch-device', [AuthController::class, 'switchDevice'], middleware: [AuthMiddleware::class], name: 'switch.device');
// POST (jeton CSRF) : la langue choisie est enregistrée dans le profil ; en GET, une simple image sur un autre site la
// changeait (audit n°8).
$router->post('/lang/{locale}', [AuthController::class, 'switchLanguage'], name: 'lang.switch');

// --- Accueil / Dashboard ---
$router->get('/', [HomeController::class, 'index'], middleware: [AuthMiddleware::class, PermissionMiddleware::class], name: 'home', permission: 'public');
$router->post('/admin/dashboard/widgets', [DashboardLayoutController::class, 'widgetsManager'], middleware: [AuthMiddleware::class, PermissionMiddleware::class], name: 'admin.dashboard.widgets', permission: 'public');
$router->post('/admin/dashboard/layout', [DashboardLayoutController::class, 'saveManager'], middleware: [AuthMiddleware::class, PermissionMiddleware::class], name: 'admin.dashboard.layout', permission: 'public');
$router->post('/admin/dashboard/layouts', [DashboardLayoutsController::class, 'manageManager'], middleware: [AuthMiddleware::class, PermissionMiddleware::class], name: 'admin.dashboard.layouts', permission: 'public');

// =============================================================================
// Routes administration (web)
// =============================================================================

$router->group('/admin', function ($r) {

    // Configuration organisation
    $r->get('/owner-settings',  [OwnerSettingsController::class, 'show'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.owner_settings', permission: 'public');
    $r->post('/owner-settings', [OwnerSettingsController::class, 'save'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.owner_settings.save', permission: 'public');

    // Navigation
    $r->get('/nav-settings',  [AdminController::class, 'navSettings'],     name: 'admin.nav_settings', permission: 'public');
    $r->post('/nav-settings', [AdminController::class, 'saveNavSettings'], name: 'admin.nav_settings.save', permission: 'public');

    // Demandes (résumé) : agrège congés, échanges de shifts et bourse aux shifts
    $r->get('/requests', [AdminRequestsController::class, 'index'], name: 'admin.requests', permission: 'public');

    // Utilisateurs
    $r->get('/users',                     [AdminUserController::class, 'users'],               name: 'admin.users', permission: 'employees.view');
    $r->get('/users/export/pdf',          [AdminUserController::class, 'exportUsersPdf'],      name: 'admin.users.export_pdf', permission: 'employees.view');
    $r->get('/users/export/pdf/download', [AdminUserController::class, 'exportUsersPdfDownload'], name: 'admin.users.export_pdf_download', permission: 'employees.view');
    $r->get('/users/export/json',         [AdminUserController::class, 'exportUsersJson'],     name: 'admin.users.export_json', permission: 'employees.view');
    $r->get('/users/create',              [AdminUserController::class, 'createUser'],          name: 'admin.users.create', permission: 'employees.create');
    $r->post('/users/create',             [AdminUserController::class, 'storeUser'],           name: 'admin.users.store', permission: 'employees.create');
    $r->post('/users/quick-create',       [AdminUserController::class, 'quickCreateUser'],     name: 'admin.users.quick_create', permission: 'employees.create');
    $r->get('/users/check-employee-code', [AdminUserController::class, 'checkEmployeeCode'],   name: 'admin.users.check_employee_code', permission: 'employees.view');
    $r->get('/users/check-email',         [AdminUserController::class, 'checkEmail'],          name: 'admin.users.check_email', permission: 'employees.view');
    $r->get('/users/{id:employee}/edit',           [AdminUserController::class, 'editUser'],            name: 'admin.users.edit', permission: 'employees.view');
    $r->post('/users/{id:employee}/edit',          [AdminUserController::class, 'updateUser'],          name: 'admin.users.update', permission: 'employees.update');
    $r->post('/users/{id:employee}/delete',        [AdminUserController::class, 'deleteUser'],          name: 'admin.users.delete', permission: 'employees.delete');
    $r->post('/users/{id:employee}/reset-password',[AdminUserController::class, 'resetPassword'],       name: 'admin.users.reset_password', permission: 'employees.update');
    $r->post('/users/{id:employee}/rates',         [AdminUserController::class, 'setUserRate'],         name: 'admin.users.rates.set', permission: 'employees.update');
    $r->post('/users/{id:employee}/rates/{rid}/delete', [AdminUserController::class, 'deleteUserRate'], name: 'admin.users.rates.delete', permission: 'employees.update');

    // Magasins
    $r->get('/stores',                    [AdminStoreController::class, 'stores'],               name: 'admin.stores', permission: 'stores.view');
    $r->get('/stores/create',             [AdminStoreController::class, 'createStore'],          name: 'admin.stores.create', permission: 'stores.create');
    $r->post('/stores/create',            [AdminStoreController::class, 'storeStore'],           name: 'admin.stores.store', permission: 'stores.create');
    $r->get('/stores/{id:store}/edit',          [AdminStoreController::class, 'editStore'],            name: 'admin.stores.edit', permission: 'stores.view');
    $r->post('/stores/{id:store}/edit',         [AdminStoreController::class, 'updateStore'],          name: 'admin.stores.update', permission: 'stores.update');
    $r->post('/stores/{id:store}/delete',       [AdminStoreController::class, 'deleteStore'],          name: 'admin.stores.delete', permission: 'stores.delete');
    $r->post('/stores/{id:store}/members',      [AdminStoreController::class, 'addMember'],            name: 'admin.stores.members.add', permission: 'employees.update');
    $r->post('/stores/{id:store}/members/{mid}/role',   [AdminStoreController::class, 'updateMemberRole'], name: 'admin.stores.members.role', permission: 'employees.update');
    $r->post('/stores/{id:store}/members/{mid}/delete', [AdminStoreController::class, 'removeMember'],      name: 'admin.stores.members.delete', permission: 'employees.update');
    $r->get('/stores/{id:store}/members/{mid}/deductions',  [AdminStoreController::class, 'editMemberDeductions'],   name: 'admin.stores.members.deductions', permission: 'payroll.view');
    $r->post('/stores/{id:store}/members/{mid}/deductions', [AdminStoreController::class, 'saveMemberDeductions'],   name: 'admin.stores.members.deductions.save', permission: 'payroll.generate');

    // Statistiques & Rapports
    $r->get('/stores/{id:store}/stats',              [AdminStoreController::class, 'storeStats'],       name: 'admin.stores.stats', permission: 'payroll.view');
    $r->get('/stores/{id:store}/stats/export',       [AdminStoreController::class, 'storeStatsExport'], name: 'admin.stores.stats_export', permission: 'payroll.export');
    $r->get('/stores/{id:store}/profitability',      [AdminStoreController::class, 'storeProfitability'], name: 'admin.stores.profitability', permission: 'payroll.view');
    $r->get('/stores/{id:store}/employee-report',                      [AdminStoreController::class, 'employeeReport'],    name: 'admin.stores.employee_report', permission: 'payroll.view');
    $r->get('/stores/{id:store}/employee-report/{uid:employee}/stats',          [AdminStoreController::class, 'employeeStats'],     name: 'admin.stores.employee_stats', permission: 'payroll.view');

    // Rapports d'embauche : bundle distribué hors monorepo, routes chargées depuis storage/bundles/hiring-report/ une fois installé (voir wiki : Architecture)

    // Démission : bundle distribué hors monorepo, routes chargées depuis storage/bundles/resignation-report/ une fois installé (voir wiki : Architecture)
    // Salaire : bundle distribué hors monorepo, routes chargées depuis storage/bundles/salary-report/ une fois installé (voir wiki : Architecture)

    // Shift types
    $r->get('/shift-types',               [AdminShiftTypeController::class, 'shiftTypes'],         name: 'admin.shift_types', permission: 'shifts.view');
    $r->get('/shift-types/create',        [AdminShiftTypeController::class, 'createShiftType'],    name: 'admin.shift_types.create', permission: 'shifts.update');
    $r->post('/shift-types/create',       [AdminShiftTypeController::class, 'storeShiftType'],     name: 'admin.shift_types.store', permission: 'shifts.update');
    $r->get('/shift-types/{id}/edit',     [AdminShiftTypeController::class, 'editShiftType'],      name: 'admin.shift_types.edit', permission: 'shifts.update');
    $r->post('/shift-types/{id}/edit',    [AdminShiftTypeController::class, 'updateShiftType'],    name: 'admin.shift_types.update', permission: 'shifts.update');
    $r->post('/shift-types/{id}/delete',  [AdminShiftTypeController::class, 'deleteShiftType'],    name: 'admin.shift_types.delete', permission: 'shifts.update');
    $r->post('/shift-types/{id}/toggle-store', [AdminShiftTypeController::class, 'toggleShiftTypeStore'], name: 'admin.shift_types.toggle_store', permission: 'shifts.update');

    // Shifts
    $r->get('/shifts',              [AdminShiftController::class, 'shifts'],               name: 'admin.shifts', permission: 'shifts.view');
    $r->get('/shifts/calendar',     [AdminShiftController::class, 'shiftsCalendar'],       name: 'admin.shifts.calendar', permission: 'shifts.view');
    $r->get('/shifts/timeline',     [AdminShiftController::class, 'shiftsTimeline'],       name: 'admin.shifts.timeline', permission: 'shifts.view');
    $r->get('/shifts/timeline/print', [AdminShiftController::class, 'shiftsTimelinePrint'], name: 'admin.shifts.timeline.print', permission: 'shifts.view');
    $r->get('/shifts/conflicts',    [AdminShiftController::class, 'shiftConflicts'],       name: 'admin.shifts.conflicts', permission: 'shifts.view');
    $r->post('/shifts/conflicts/resolve-newer', [AdminShiftController::class, 'resolveNewerConflict'], name: 'admin.shifts.resolve_newer', permission: 'shifts.update');

    $r->get('/shifts/import',       [AdminShiftImportController::class, 'importShifts'],         name: 'admin.shifts.import', permission: 'shifts.import');
    $r->post('/shifts/import',      [AdminShiftImportController::class, 'processImport'],        name: 'admin.shifts.import.process', permission: 'shifts.import');
    $r->post('/shifts/import/confirm', [AdminShiftImportController::class, 'confirmImport'],     name: 'admin.shifts.import.confirm', permission: 'shifts.import');
    $r->get('/shifts/create',       [AdminShiftController::class, 'createShift'],          name: 'admin.shifts.create', permission: 'shifts.create');
    $r->get('/shifts/wage-preview', [AdminShiftController::class, 'wagePreview'],          name: 'admin.shifts.wage_preview', permission: 'shifts.view');
    $r->post('/shifts/create',      [AdminShiftController::class, 'storeShift'],           name: 'admin.shifts.store', permission: 'shifts.create');
    $r->post('/shifts/quick',       [AdminShiftController::class, 'quickShift'],           name: 'admin.shifts.quick', permission: 'shifts.create');
    $r->post('/shifts/bulk-delete', [AdminShiftController::class, 'bulkDeleteShifts'],     name: 'admin.shifts.bulk_delete', permission: 'shifts.delete');
    $r->get('/shifts/{id}/edit',    [AdminShiftController::class, 'editShift'],            name: 'admin.shifts.edit', permission: 'shifts.view');
    $r->post('/shifts/{id}/edit',   [AdminShiftController::class, 'updateShift'],          name: 'admin.shifts.update', permission: 'shifts.update');
    $r->post('/shifts/{id}/delete', [AdminShiftController::class, 'deleteShift'],          name: 'admin.shifts.delete', permission: 'shifts.delete');
    $r->post('/shifts/{id}/move',   [AdminShiftController::class, 'moveShift'],            name: 'admin.shifts.move', permission: 'shifts.update');

    // Bourse aux shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-claim/ une fois installé (voir wiki : Architecture)

    // Demandes de congé : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeoff/ une fois installé (voir wiki : Architecture)

    // Échanges de shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-swap/ une fois installé (voir wiki : Architecture)

    // Pointage : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeclock/ une fois installé (voir wiki : Architecture)

    // Journal d'activité (unifié)
    $r->get('/activity', [ActivityController::class, 'index'], name: 'admin.activity', permission: 'stores.view');
    $r->get('/activity/export', [ActivityController::class, 'export'], name: 'admin.activity.export', permission: 'stores.view');
    $r->post('/activity/purge', [ActivityController::class, 'purge'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.activity.purge', permission: 'public');


    // Diagnostic mail
    $r->get('/mail-test',  [MailTestController::class, 'show'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.mail_test', permission: 'public');
    $r->post('/mail-test', [MailTestController::class, 'send'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.mail_test.send', permission: 'public');

    // Notifications de l'instance : conservation, état des canaux, notification de test, clés du web push.
    $r->get('/notifications-settings',          [NotificationSettingsController::class, 'show'],            middleware: [OwnerOnlyMiddleware::class], name: 'admin.notifications_settings', permission: 'public');
    $r->post('/notifications-settings',         [NotificationSettingsController::class, 'save'],            middleware: [OwnerOnlyMiddleware::class], name: 'admin.notifications_settings.save', permission: 'public');
    $r->post('/notifications-settings/test',    [NotificationSettingsController::class, 'sendTest'],        middleware: [OwnerOnlyMiddleware::class], name: 'admin.notifications_settings.test', permission: 'public');
    $r->post('/notifications-settings/vapid',   [NotificationSettingsController::class, 'regenerateVapid'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.notifications_settings.vapid', permission: 'public');

    // Agendas externes : identifiants Google de l'instance (Owner).
    $r->get('/calendar-settings',               [CalendarSettingsController::class, 'show'],                middleware: [OwnerOnlyMiddleware::class], name: 'admin.calendar_settings', permission: 'public');
    $r->post('/calendar-settings',              [CalendarSettingsController::class, 'save'],                middleware: [OwnerOnlyMiddleware::class], name: 'admin.calendar_settings.save', permission: 'public');
    $r->post('/calendar-settings/test',         [CalendarSettingsController::class, 'test'],                middleware: [OwnerOnlyMiddleware::class], name: 'admin.calendar_settings.test', permission: 'public');

    // Sauvegardes
    $r->get('/backup',               [BackupController::class, 'index'],  middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup', permission: 'public');
    $r->get('/backup/download',      [BackupController::class, 'download'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup.download', permission: 'public');
    $r->post('/backup/create',       [BackupController::class, 'create'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup.create', permission: 'public');
    $r->post('/backup/restore',      [BackupController::class, 'restore'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup.restore', permission: 'public');
    $r->post('/backup/delete',       [BackupController::class, 'delete'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup.delete', permission: 'public');
    $r->post('/backup/delete-all',   [BackupController::class, 'deleteAll'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup.delete_all', permission: 'public');
    $r->post('/backup/settings',     [BackupController::class, 'saveSettings'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.backup.settings', permission: 'public');

    // Réinitialisation de l'application ("danger zone")
    $r->post('/reset/prepare', [AppResetController::class, 'prepare'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.reset.prepare', permission: 'public');
    $r->post('/reset/execute', [AppResetController::class, 'execute'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.reset.execute', permission: 'public');

    // Mises à jour
    $r->get('/update',               [BackupController::class, 'updatePage'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.update', permission: 'public');
    $r->post('/update/apply',        [BackupController::class, 'update'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.update.apply', permission: 'public');
    $r->post('/update/stream',       [BackupController::class, 'updateStream'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.update.stream', permission: 'public');
    $r->post('/update/migrate',      [BackupController::class, 'migrate'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.update.migrate', permission: 'public');
    $r->post('/update/channel',      [BackupController::class, 'saveChannel'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.update.channel', permission: 'public');

    // Photos : bundle distribué hors monorepo, routes chargées depuis storage/bundles/store-photos/ une fois installé (voir wiki : Architecture)

    // Langues & traductions (Owner uniquement)
    $r->get('/languages',                       [LanguageController::class, 'index'],        middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages', permission: 'public');
    $r->post('/languages',                      [LanguageController::class, 'store'],        middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages.store', permission: 'public');
    $r->post('/languages/{code}/toggle-active', [LanguageController::class, 'toggleActive'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages.toggle_active', permission: 'public');
    $r->post('/languages/{code}/delete',        [LanguageController::class, 'destroy'],      middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages.delete', permission: 'public');
    $r->get('/languages/{code}/edit',           [LanguageController::class, 'edit'],         middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages.edit', permission: 'public');
    $r->post('/languages/{code}/edit/save',     [LanguageController::class, 'saveKey'],      middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages.edit.save', permission: 'public');
    $r->post('/languages/{code}/edit/delete',   [LanguageController::class, 'deleteKey'],    middleware: [OwnerOnlyMiddleware::class], name: 'admin.languages.edit.delete', permission: 'public');

    // Bundles (Owner uniquement)
    $r->get('/bundles',  [BundleSettingsController::class, 'show'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles', permission: 'public');
    $r->post('/bundles', [BundleSettingsController::class, 'save'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.save', permission: 'public');
    $r->get('/bundles/registries',              [BundleMarketController::class, 'index'],   middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.registries', permission: 'public');
    $r->post('/bundles/registries',             [BundleMarketController::class, 'store'],   middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.registries.store', permission: 'public');
    $r->post('/bundles/registries/{id}/delete', [BundleMarketController::class, 'destroy'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.registries.delete', permission: 'public');
    $r->get('/bundles/market',                  [BundleMarketController::class, 'market'],        middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.market', permission: 'public');
    $r->post('/bundles/market/dry-run',         [BundleMarketController::class, 'dryRun'],        middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.market.dry-run', permission: 'public');
    $r->post('/bundles/market/install',         [BundleMarketController::class, 'install'],       middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.market.install', permission: 'public');
    $r->post('/bundles/market/install/stream',  [BundleMarketController::class, 'installStream'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.market.install.stream', permission: 'public');
    $r->post('/bundles/market/uninstall',       [BundleMarketController::class, 'uninstall'],     middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.market.uninstall', permission: 'public');
    $r->post('/bundles/market/channel',         [BundleMarketController::class, 'saveChannel'],   middleware: [OwnerOnlyMiddleware::class], name: 'admin.bundles.market.channel', permission: 'public');

    // Licence (déblocage du plan payant, Owner uniquement)
    // Assistant de configuration ouvert par l'installateur : licence, puis bundles (voir SetupWizard).
    $r->get('/setup',          [SetupWizardController::class, 'show'],    middleware: [OwnerOnlyMiddleware::class], name: 'admin.setup', permission: 'public');
    $r->post('/setup/license', [SetupWizardController::class, 'license'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.setup.license', permission: 'public');
    $r->post('/setup/step/{step}', [SetupWizardController::class, 'step'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.setup.step', permission: 'public');
    $r->post('/setup/finish', [SetupWizardController::class, 'finish'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.setup.finish', permission: 'public');
    $r->post('/setup/bundles', [SetupWizardController::class, 'bundles'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.setup.bundles', permission: 'public');
    $r->post('/setup/questions', [SetupWizardController::class, 'questions'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.setup.questions', permission: 'public');
    $r->get('/license',           [LicenseController::class, 'show'],       middleware: [OwnerOnlyMiddleware::class], name: 'admin.license', permission: 'public');
    $r->post('/license/activate', [LicenseController::class, 'activate'],   middleware: [OwnerOnlyMiddleware::class], name: 'admin.license.activate', permission: 'public');
    $r->post('/license/refresh',  [LicenseController::class, 'refresh'],    middleware: [OwnerOnlyMiddleware::class], name: 'admin.license.refresh', permission: 'public');
    $r->post('/license/deactivate', [LicenseController::class, 'deactivate'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.license.deactivate', permission: 'public');

    // Rôles & permissions (Owner uniquement) — voir task/mermission.md
    $r->get('/roles',              [AdminRoleController::class, 'roles'],      middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles', permission: 'public');
    $r->get('/roles/create',       [AdminRoleController::class, 'createRole'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.create', permission: 'public');
    $r->post('/roles/create',      [AdminRoleController::class, 'storeRole'],  middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.store', permission: 'public');
    $r->get('/roles/{id}/edit',    [AdminRoleController::class, 'editRole'],   middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.edit', permission: 'public');
    $r->post('/roles/{id}/edit',   [AdminRoleController::class, 'updateRole'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.update', permission: 'public');
    $r->post('/roles/{id}/delete', [AdminRoleController::class, 'deleteRole'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.delete', permission: 'public');
    $r->post('/roles/{id}/holders',                       [AdminRoleController::class, 'addHolder'],    middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.holders.add',    permission: 'public');
    $r->post('/roles/{id}/holders/{assignmentId}/delete', [AdminRoleController::class, 'removeHolder'], middleware: [OwnerOnlyMiddleware::class], name: 'admin.roles.holders.delete', permission: 'public');

}, middleware: [AuthMiddleware::class, PermissionMiddleware::class]);

// =============================================================================
// API v1
// =============================================================================

// --- Routes publiques ---
$router->get('/api/v1/ping',       [ApiAuthController::class, 'ping'],  name: 'api.v1.ping');
$router->post('/api/v1/auth/login', [ApiAuthController::class, 'login'], middleware: [LoginThrottleMiddleware::class], name: 'api.v1.auth.login');

// --- Routes protégées par token Bearer ---
$router->group('/api/v1', function ($r) {

    // Auth
    $r->post('/auth/logout',        [ApiAuthController::class, 'logout'],      name: 'api.v1.auth.logout', permission: 'public');
    $r->get('/auth/me',             [ApiAuthController::class, 'me'],          name: 'api.v1.auth.me', permission: 'public');
    $r->get('/auth/tokens',         [ApiAuthController::class, 'listTokens'],  name: 'api.v1.auth.tokens', permission: 'public');
    $r->delete('/auth/tokens/{id}', [ApiAuthController::class, 'revokeToken'], name: 'api.v1.auth.tokens.revoke', permission: 'public');

    // Users
    $r->get('/users',         [ApiUserController::class, 'index'],   name: 'api.v1.users.index', permission: 'employees.view');
    $r->post('/users',        [ApiUserController::class, 'store'],   name: 'api.v1.users.store', permission: 'employees.create');
    $r->get('/users/{id}',    [ApiUserController::class, 'show'],    name: 'api.v1.users.show', permission: ['perm' => 'employees.view', 'self' => 'id']);
    $r->put('/users/{id}',    [ApiUserController::class, 'update'],  name: 'api.v1.users.update', permission: 'employees.update');
    $r->delete('/users/{id}', [ApiUserController::class, 'destroy'], name: 'api.v1.users.destroy', permission: 'employees.delete');

    // Users — prefs, rates, ical-tokens
    $r->get('/users/{user_id}/dashboard-prefs',                       [ApiUserPrefsController::class, 'getDashboardPrefs'],  name: 'api.v1.users.dashboard_prefs.get', permission: ['perm' => 'employees.view', 'self' => 'user_id']);
    $r->put('/users/{user_id}/dashboard-prefs',                       [ApiUserPrefsController::class, 'saveDashboardPrefs'], name: 'api.v1.users.dashboard_prefs.save', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->get('/users/{user_id}/nav-prefs',                             [ApiUserPrefsController::class, 'getNavPrefs'],        name: 'api.v1.users.nav_prefs.get', permission: ['perm' => 'employees.view', 'self' => 'user_id']);
    $r->put('/users/{user_id}/nav-prefs',                             [ApiUserPrefsController::class, 'saveNavPrefs'],       name: 'api.v1.users.nav_prefs.save', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->get('/users/{user_id}/rates',                                 [ApiUserShiftRateController::class, 'index'],          name: 'api.v1.users.rates.index', permission: ['perm' => 'payroll.view', 'self' => 'user_id']);
    $r->post('/users/{user_id}/rates',                                [ApiUserShiftRateController::class, 'store'],          name: 'api.v1.users.rates.store', permission: 'employees.update');
    $r->get('/users/{user_id}/rates/{id}',                            [ApiUserShiftRateController::class, 'show'],           name: 'api.v1.users.rates.show', permission: ['perm' => 'payroll.view', 'self' => 'user_id']);
    $r->put('/users/{user_id}/rates/{id}',                            [ApiUserShiftRateController::class, 'update'],         name: 'api.v1.users.rates.update', permission: 'employees.update');
    $r->delete('/users/{user_id}/rates/{id}',                         [ApiUserShiftRateController::class, 'destroy'],        name: 'api.v1.users.rates.destroy', permission: 'employees.update');
    // Users — jetons d'appareil push (notifications mobiles, FCM)
    $r->get('/users/{user_id}/notification-preferences',             [ApiNotificationPreferenceController::class, 'show'],   name: 'api.v1.users.notification_preferences.show', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->put('/users/{user_id}/notification-preferences',             [ApiNotificationPreferenceController::class, 'update'], name: 'api.v1.users.notification_preferences.update', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->post('/users/{user_id}/push-tokens',                          [ApiPushTokenController::class, 'store'],              name: 'api.v1.users.push_tokens.store', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->delete('/users/{user_id}/push-tokens',                        [ApiPushTokenController::class, 'destroy'],            name: 'api.v1.users.push_tokens.destroy', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->get('/users/{user_id}/ical-tokens',                           [ApiIcalTokenController::class, 'index'],              name: 'api.v1.users.ical_tokens.index', permission: ['perm' => 'employees.view', 'self' => 'user_id']);
    $r->post('/users/{user_id}/ical-tokens',                          [ApiIcalTokenController::class, 'store'],              name: 'api.v1.users.ical_tokens.store', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->post('/users/{user_id}/ical-tokens/{store_id}/regenerate',    [ApiIcalTokenController::class, 'regenerate'],         name: 'api.v1.users.ical_tokens.regenerate', permission: ['perm' => 'employees.update', 'self' => 'user_id']);
    $r->get('/users/{user_id}/ical-tokens/{store_id}',                [ApiIcalTokenController::class, 'show'],               name: 'api.v1.users.ical_tokens.show', permission: ['perm' => 'employees.view', 'self' => 'user_id']);
    $r->delete('/users/{user_id}/ical-tokens/{store_id}',             [ApiIcalTokenController::class, 'destroy'],            name: 'api.v1.users.ical_tokens.destroy', permission: ['perm' => 'employees.update', 'self' => 'user_id']);

    // Stores
    $r->get('/stores',         [ApiStoreController::class, 'index'],   name: 'api.v1.stores.index', permission: 'stores.view');
    $r->post('/stores',        [ApiStoreController::class, 'store'],   name: 'api.v1.stores.store', permission: 'stores.create');
    $r->get('/stores/{id}',    [ApiStoreController::class, 'show'],    name: 'api.v1.stores.show', permission: 'stores.view');
    $r->put('/stores/{id}',    [ApiStoreController::class, 'update'],  name: 'api.v1.stores.update', permission: 'stores.update');
    $r->delete('/stores/{id}', [ApiStoreController::class, 'destroy'], name: 'api.v1.stores.destroy', permission: 'stores.delete');

    // Stores — membres
    $r->get('/stores/{store_id}/members',         [ApiStoreUserController::class, 'index'],   name: 'api.v1.store_members.index', permission: 'employees.view');
    $r->post('/stores/{store_id}/members',        [ApiStoreUserController::class, 'store'],   name: 'api.v1.store_members.store', permission: 'employees.update');
    $r->get('/stores/{store_id}/members/{id}',    [ApiStoreUserController::class, 'show'],    name: 'api.v1.store_members.show', permission: 'employees.view');
    $r->put('/stores/{store_id}/members/{id}',    [ApiStoreUserController::class, 'update'],  name: 'api.v1.store_members.update', permission: 'employees.update');
    $r->delete('/stores/{store_id}/members/{id}', [ApiStoreUserController::class, 'destroy'], name: 'api.v1.store_members.destroy', permission: 'employees.update');

    // Shift types
    $r->get('/shift-types',         [ApiShiftTypeController::class, 'index'],   name: 'api.v1.shift_types.index', permission: 'shifts.view');
    $r->post('/shift-types',        [ApiShiftTypeController::class, 'store'],   name: 'api.v1.shift_types.store', permission: 'shifts.update');
    $r->get('/shift-types/{id}',    [ApiShiftTypeController::class, 'show'],    name: 'api.v1.shift_types.show', permission: 'shifts.view');
    $r->put('/shift-types/{id}',    [ApiShiftTypeController::class, 'update'],  name: 'api.v1.shift_types.update', permission: 'shifts.update');
    $r->delete('/shift-types/{id}', [ApiShiftTypeController::class, 'destroy'], name: 'api.v1.shift_types.destroy', permission: 'shifts.update');

    // Shifts
    $r->get('/shifts',         [ApiShiftController::class, 'index'],   name: 'api.v1.shifts.index', permission: ['perm' => 'shifts.view', 'self' => 'user_id']);
    $r->post('/shifts',        [ApiShiftController::class, 'store'],   name: 'api.v1.shifts.store', permission: 'shifts.create');
    $r->get('/shifts/{id}',    [ApiShiftController::class, 'show'],    name: 'api.v1.shifts.show', permission: 'shifts.view');
    $r->put('/shifts/{id}',    [ApiShiftController::class, 'update'],  name: 'api.v1.shifts.update', permission: 'shifts.update');
    $r->delete('/shifts/{id}', [ApiShiftController::class, 'destroy'], name: 'api.v1.shifts.destroy', permission: 'shifts.delete');

    // Disponibilités
    $r->get('/availabilities',         [ApiAvailabilityController::class, 'index'],   name: 'api.v1.availabilities.index', permission: 'shifts.view');
    $r->post('/availabilities',        [ApiAvailabilityController::class, 'store'],   name: 'api.v1.availabilities.store', permission: 'shifts.update');
    $r->get('/availabilities/{id}',    [ApiAvailabilityController::class, 'show'],    name: 'api.v1.availabilities.show', permission: 'shifts.view');
    $r->put('/availabilities/{id}',    [ApiAvailabilityController::class, 'update'],  name: 'api.v1.availabilities.update', permission: 'shifts.update');
    $r->delete('/availabilities/{id}', [ApiAvailabilityController::class, 'destroy'], name: 'api.v1.availabilities.destroy', permission: 'shifts.update');

    // Demandes de congé : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeoff/ une fois installé (voir wiki : Architecture)

    // Échanges de shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-swap/ une fois installé (voir wiki : Architecture)

    // Pointage : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeclock/ une fois installé (voir wiki : Architecture)

    // Bourse aux shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-claim/ une fois installé (voir wiki : Architecture)

    // Notifications
    $r->post('/notifications/read-all',    [ApiNotificationController::class, 'markAllRead'], name: 'api.v1.notifications.read_all', permission: 'public');
    $r->get('/notifications',              [ApiNotificationController::class, 'index'],       name: 'api.v1.notifications.index', permission: 'public');
    $r->get('/notifications/{id}',         [ApiNotificationController::class, 'show'],        name: 'api.v1.notifications.show', permission: 'public');
    $r->post('/notifications/{id}/read',   [ApiNotificationController::class, 'markRead'],    name: 'api.v1.notifications.read', permission: 'public');
    $r->delete('/notifications/{id}',      [ApiNotificationController::class, 'destroy'],     name: 'api.v1.notifications.destroy', permission: 'public');


    // Journal d'activité
    $r->get('/activity', [ApiActivityController::class, 'index'], name: 'api.v1.activity.index', permission: 'stores.view');

}, middleware: [ApiAuthMiddleware::class, ApiPermissionMiddleware::class]);
