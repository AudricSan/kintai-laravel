<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use App\Http\Middleware\LoginThrottleMiddleware;
use App\Http\Middleware\OwnerOnlyMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RateLimiterMiddleware;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleAssetController;
use App\Http\Controllers\CalendarLinkController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardLayoutController;
use App\Http\Controllers\DashboardLayoutsController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IcalController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationUnsubscribeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\Requests\AdminRequestsController;
use App\Http\Controllers\ScheduleExportController;
use App\Http\Controllers\Scheduling\AdminShiftController;
use App\Http\Controllers\Scheduling\AdminShiftImportController;
use App\Http\Controllers\Scheduling\AdminShiftTypeController;
use App\Http\Controllers\Scheduling\AdminShiftWeekController;
use App\Http\Controllers\Scheduling\AdminTimesheetController;
use App\Http\Controllers\Staff\AdminStoreController;
use App\Http\Controllers\Staff\AdminUserController;
use App\Http\Controllers\StorageFileController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\System\ActivityController;
use App\Http\Controllers\System\AdminController;
use App\Http\Controllers\System\AdminRoleController;
use App\Http\Controllers\System\AppResetController;
use App\Http\Controllers\System\BackupController;
use App\Http\Controllers\System\BundleMarketController;
use App\Http\Controllers\System\BundleSettingsController;
use App\Http\Controllers\System\CalendarSettingsController;
use App\Http\Controllers\System\LanguageController;
use App\Http\Controllers\System\LicenseController;
use App\Http\Controllers\System\MailTestController;
use App\Http\Controllers\System\NotificationSettingsController;
use App\Http\Controllers\System\OwnerSettingsController;
use App\Http\Controllers\System\SetupWizardController;

// =============================================================================
// Routes publiques (sans authentification)
// =============================================================================

// --- CRON ---
Route::get('/cron/run/{job}', [CronController::class, 'run'])->name('cron.run');

// --- iCal ---
Route::get('/ical/{token}/{store_id}/shifts.ics', [IcalController::class, 'feed'])->name('ical.feed');

// --- Authentification ---
Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.login');
Route::post('/login', [AuthController::class, 'login'])->middleware([LoginThrottleMiddleware::class])->name('auth.login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

// --- Réinitialisation de mot de passe ---
Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.forgot');
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware([RateLimiterMiddleware::class])->name('password.forgot.post');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password/{token}', [PasswordResetController::class, 'reset'])->middleware([RateLimiterMiddleware::class])->name('password.reset.post');

// Désabonnement d'un type d'e-mail de notification : lien signé ouvert depuis le client e-mail, sans connexion.
Route::get('/notifications/unsubscribe', [NotificationUnsubscribeController::class, 'show'])->middleware([RateLimiterMiddleware::class])->name('notifications.unsubscribe');
Route::post('/notifications/unsubscribe', [NotificationUnsubscribeController::class, 'confirm'])->middleware([RateLimiterMiddleware::class])->name('notifications.unsubscribe.confirm');

// --- PWA ---
Route::get('/manifest.json', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.service_worker');

// --- Confidentialité & pages légales (footer) ---
Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');
Route::get('/legal/mentions', [LegalController::class, 'mentions'])->name('legal.mentions');
Route::get('/legal/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/legal/license', [LegalController::class, 'license'])->name('legal.license');

// --- Fichiers uploadés (photos de stores, imports) — réservé aux admins/managers ---
Route::get('/storage/{path}', [StorageFileController::class, 'serve'])->where('path', '.+')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->name('storage.file')->permission('public');

// --- Assets statiques d'un bundle actif (CSS/JS) — publics, pas d'authentification ---
Route::get('/bundle-assets/{slug}/{path}', [BundleAssetController::class, 'serve'])->where('path', '.+')->name('bundle.asset');

// =============================================================================
// Routes authentifiées (tout utilisateur connecté)
// =============================================================================

Route::prefix('profile')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('', [AuthController::class, 'showProfile'])->name('profile');
    Route::post('', [AuthController::class, 'updateProfile'])->name('profile.post');
    Route::post('/avatar', [AuthController::class, 'uploadAvatar'])->name('profile.avatar');
    Route::post('/avatar/delete', [AuthController::class, 'removeAvatar'])->name('profile.avatar.delete');
    Route::post('/password', [AuthController::class, 'saveProfilePassword'])->name('profile.password');
    Route::post('/notifications', [AuthController::class, 'saveNotificationPreferences'])->name('profile.notifications');
    // Liaison de l'agenda Google de l'utilisateur (OAuth, Kintai → Google).
    Route::post('/calendar/google/connect', [CalendarLinkController::class, 'connect'])->name('profile.calendar.connect');
    Route::get('/calendar/google/callback', [CalendarLinkController::class, 'callback'])->name('profile.calendar.callback');
    Route::post('/calendar/google/disconnect', [CalendarLinkController::class, 'disconnect'])->name('profile.calendar.disconnect');
    Route::post('/calendar/google/options', [CalendarLinkController::class, 'options'])->name('profile.calendar.options');
    Route::post('/calendar/google/resync', [CalendarLinkController::class, 'resync'])->name('profile.calendar.resync');
    Route::get('/export', [AuthController::class, 'exportData'])->name('profile.export');
    // Son propre horaire en JSON, CSV ou ICS (fichier ponctuel, contrairement au flux iCal à jeton).
    Route::get('/schedule-export', [ScheduleExportController::class, 'download'])->name('profile.schedule_export');
    Route::post('/delete', [AuthController::class, 'deleteAccount'])->name('profile.delete');
});

// Photo de profil : servie hors du groupe /profile pour rester consultable
// via l'ID d'un autre utilisateur (annuaire collègues, bundle TeamDirectory).
Route::get('/avatar/{employee}', [AuthController::class, 'avatar'])->middleware([AuthMiddleware::class, SubstituteBindings::class])->name('user.avatar');

// Exports de données (Core et bundles) en JSON, CSV, Excel ou ICS. Les droits sont décidés par jeu de données et par magasin
// dans ExportAccess, pas par une permission de route : chacun peut au moins exporter ses propres données.
Route::get('/exports', [ExportController::class, 'index'])->middleware([AuthMiddleware::class])->name('exports.index');
Route::get('/exports/download', [ExportController::class, 'download'])->middleware([AuthMiddleware::class])->name('exports.download');

Route::prefix('notifications')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
    Route::post('/delete-all', [NotificationController::class, 'deleteAll'])->name('notifications.delete_all');
    Route::post('/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/push-subscribe', [NotificationController::class, 'pushSubscribe'])->name('notifications.push_subscribe');
    Route::post('/push-unsubscribe', [NotificationController::class, 'pushUnsubscribe'])->name('notifications.push_unsubscribe');
    Route::post('/push-status', [NotificationController::class, 'pushStatus'])->name('notifications.push_status');
});

// --- Support (footer : "signaler un problème" → issue GitHub) ---
Route::post('/support/report-issue', [SupportController::class, 'reportIssue'])->middleware([AuthMiddleware::class, RateLimiterMiddleware::class])->name('support.report_issue');

// --- Documentation ---
Route::get('/docs', [DocsController::class, 'index'])->middleware([AuthMiddleware::class])->name('docs.index');
Route::post('/docs/sync', [DocsController::class, 'sync'])->middleware([AuthMiddleware::class, OwnerOnlyMiddleware::class])->name('docs.sync');
Route::get('/docs/{lang}/{page}', [DocsController::class, 'show'])->middleware([AuthMiddleware::class])->name('docs.show');

Route::prefix('employee')->middleware([AuthMiddleware::class])->group(function () {

    // Dashboard
    Route::get('', [EmployeeController::class, 'dashboard'])->name('employee.dashboard');
    Route::post('/dashboard/widgets', [DashboardLayoutController::class, 'widgetsEmployee'])->name('employee.dashboard.widgets');
    Route::post('/dashboard/layout', [DashboardLayoutController::class, 'saveEmployee'])->name('employee.dashboard.layout');
    Route::post('/dashboard/layouts', [DashboardLayoutsController::class, 'manageEmployee'])->name('employee.dashboard.layouts');

    // Shifts
    Route::get('/shifts', [EmployeeController::class, 'shifts'])->name('employee.shifts');
    Route::get('/shifts/calendar', [EmployeeController::class, 'shiftsCalendar'])->name('employee.shifts.calendar');
    Route::get('/shifts/day', [EmployeeController::class, 'shiftDay'])->name('employee.shifts.day');
    Route::get('/shifts/week', [EmployeeController::class, 'shiftsWeek'])->name('employee.shifts.week');

    // Pointage : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeclock/ une fois installé (voir wiki : Architecture)

    // Congés : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeoff/ une fois installé (voir wiki : Architecture)

    // Profil : géré par /profile (AuthController, page unique admin/manager/employé)
    Route::get('/profile', [EmployeeController::class, 'profile'])->name('employee.profile');
    Route::post('/profile/availability', [EmployeeController::class, 'saveProfileAvailability'])->name('employee.profile.availability');

    // Navigation
    Route::get('/nav-settings', [EmployeeController::class, 'navSettings'])->name('employee.nav_settings');
    Route::post('/nav-settings', [EmployeeController::class, 'saveNavSettings'])->name('employee.nav_settings.save');


    // Échanges de shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-swap/ une fois installé (voir wiki : Architecture)

    // Bourse aux shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-claim/ une fois installé (voir wiki : Architecture)

    // Messagerie : bundle distribué hors monorepo, routes chargées depuis storage/bundles/messaging/ une fois installé (employee.messages*, voir wiki : Architecture)

});

// --- Changement de vue / langue ---
Route::post('/switch-device', [AuthController::class, 'switchDevice'])->middleware([AuthMiddleware::class])->name('switch.device');
// POST (jeton CSRF) : la langue choisie est enregistrée dans le profil ; en GET, une simple image sur un autre site la
// changeait (audit n°8).
Route::post('/lang/{locale}', [AuthController::class, 'switchLanguage'])->name('lang.switch');

// --- Accueil / Dashboard ---
Route::get('/', [HomeController::class, 'index'])->middleware([AuthMiddleware::class, PermissionMiddleware::class])->name('home')->permission('public');
Route::post('/admin/dashboard/widgets', [DashboardLayoutController::class, 'widgetsManager'])->middleware([AuthMiddleware::class, PermissionMiddleware::class])->name('admin.dashboard.widgets')->permission('public');
Route::post('/admin/dashboard/layout', [DashboardLayoutController::class, 'saveManager'])->middleware([AuthMiddleware::class, PermissionMiddleware::class])->name('admin.dashboard.layout')->permission('public');
Route::post('/admin/dashboard/layouts', [DashboardLayoutsController::class, 'manageManager'])->middleware([AuthMiddleware::class, PermissionMiddleware::class])->name('admin.dashboard.layouts')->permission('public');

// =============================================================================
// Routes administration (web)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class, SubstituteBindings::class])->group(function () {

    // Configuration organisation
    Route::get('/owner-settings', [OwnerSettingsController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.owner_settings')->permission('public');
    Route::post('/owner-settings', [OwnerSettingsController::class, 'save'])->middleware([OwnerOnlyMiddleware::class])->name('admin.owner_settings.save')->permission('public');

    // Navigation
    Route::get('/nav-settings', [AdminController::class, 'navSettings'])->name('admin.nav_settings')->permission('public');
    Route::post('/nav-settings', [AdminController::class, 'saveNavSettings'])->name('admin.nav_settings.save')->permission('public');

    // Demandes (résumé) : agrège congés, échanges de shifts et bourse aux shifts
    Route::get('/requests', [AdminRequestsController::class, 'index'])->name('admin.requests')->permission('public');

    // Utilisateurs
    Route::get('/users', [AdminUserController::class, 'users'])->name('admin.users')->permission('employees.view');
    Route::get('/users/export/pdf', [AdminUserController::class, 'exportUsersPdf'])->name('admin.users.export_pdf')->permission('employees.view');
    Route::get('/users/export/pdf/download', [AdminUserController::class, 'exportUsersPdfDownload'])->name('admin.users.export_pdf_download')->permission('employees.view');
    Route::get('/users/export/json', [AdminUserController::class, 'exportUsersJson'])->name('admin.users.export_json')->permission('employees.view');
    Route::get('/users/create', [AdminUserController::class, 'createUser'])->name('admin.users.create')->permission('employees.create');
    Route::post('/users/create', [AdminUserController::class, 'storeUser'])->name('admin.users.store')->permission('employees.create');
    Route::post('/users/quick-create', [AdminUserController::class, 'quickCreateUser'])->name('admin.users.quick_create')->permission('employees.create');
    Route::get('/users/check-employee-code', [AdminUserController::class, 'checkEmployeeCode'])->name('admin.users.check_employee_code')->permission('employees.view');
    Route::get('/users/check-email', [AdminUserController::class, 'checkEmail'])->name('admin.users.check_email')->permission('employees.view');
    Route::get('/users/{employee}/edit', [AdminUserController::class, 'editUser'])->name('admin.users.edit')->permission('employees.view');
    Route::post('/users/{employee}/edit', [AdminUserController::class, 'updateUser'])->name('admin.users.update')->permission('employees.update');
    Route::post('/users/{employee}/delete', [AdminUserController::class, 'deleteUser'])->name('admin.users.delete')->permission('employees.delete');
    Route::post('/users/{employee}/reset-password', [AdminUserController::class, 'resetPassword'])->name('admin.users.reset_password')->permission('employees.update');
    Route::post('/users/{employee}/rates', [AdminUserController::class, 'setUserRate'])->name('admin.users.rates.set')->permission('employees.update');
    Route::post('/users/{employee}/rates/{rid}/delete', [AdminUserController::class, 'deleteUserRate'])->name('admin.users.rates.delete')->permission('employees.update');

    // Magasins
    Route::get('/stores', [AdminStoreController::class, 'stores'])->name('admin.stores')->permission('stores.view');
    Route::get('/stores/create', [AdminStoreController::class, 'createStore'])->name('admin.stores.create')->permission('stores.create');
    Route::post('/stores/create', [AdminStoreController::class, 'storeStore'])->name('admin.stores.store')->permission('stores.create');
    Route::get('/stores/{store}/edit', [AdminStoreController::class, 'editStore'])->name('admin.stores.edit')->permission('stores.view');
    Route::post('/stores/{store}/edit', [AdminStoreController::class, 'updateStore'])->name('admin.stores.update')->permission('stores.update');
    Route::post('/stores/{store}/delete', [AdminStoreController::class, 'deleteStore'])->name('admin.stores.delete')->permission('stores.delete');
    Route::post('/stores/{store}/members', [AdminStoreController::class, 'addMember'])->name('admin.stores.members.add')->permission('employees.update');
    Route::post('/stores/{store}/members/{mid}/role', [AdminStoreController::class, 'updateMemberRole'])->name('admin.stores.members.role')->permission('employees.update');
    Route::post('/stores/{store}/members/{mid}/delete', [AdminStoreController::class, 'removeMember'])->name('admin.stores.members.delete')->permission('employees.update');
    Route::get('/stores/{store}/members/{mid}/deductions', [AdminStoreController::class, 'editMemberDeductions'])->name('admin.stores.members.deductions')->permission('payroll.view');
    Route::post('/stores/{store}/members/{mid}/deductions', [AdminStoreController::class, 'saveMemberDeductions'])->name('admin.stores.members.deductions.save')->permission('payroll.generate');

    // Statistiques & Rapports
    Route::get('/stores/{store}/stats', [AdminStoreController::class, 'storeStats'])->name('admin.stores.stats')->permission('payroll.view');
    Route::get('/stores/{store}/stats/export', [AdminStoreController::class, 'storeStatsExport'])->name('admin.stores.stats_export')->permission('payroll.export');
    Route::get('/stores/{store}/profitability', [AdminStoreController::class, 'storeProfitability'])->name('admin.stores.profitability')->permission('payroll.view');
    Route::get('/employee-report', [AdminStoreController::class, 'employeeReportAll'])->name('admin.employee_report_all')->permission('payroll.view');
    Route::get('/stores/{store}/employee-report', [AdminStoreController::class, 'employeeReport'])->name('admin.stores.employee_report')->permission('payroll.view');
    Route::get('/stores/{store}/employee-report/{employee}/stats', [AdminStoreController::class, 'employeeStats'])->name('admin.stores.employee_stats')->permission('payroll.view');

    // Rapports d'embauche : bundle distribué hors monorepo, routes chargées depuis storage/bundles/hiring-report/ une fois installé (voir wiki : Architecture)

    // Démission : bundle distribué hors monorepo, routes chargées depuis storage/bundles/resignation-report/ une fois installé (voir wiki : Architecture)
    // Salaire : bundle distribué hors monorepo, routes chargées depuis storage/bundles/salary-report/ une fois installé (voir wiki : Architecture)

    // Shift types
    Route::get('/shift-types', [AdminShiftTypeController::class, 'shiftTypes'])->name('admin.shift_types')->permission('shifts.view');
    Route::get('/shift-types/create', [AdminShiftTypeController::class, 'createShiftType'])->name('admin.shift_types.create')->permission('shifts.update');
    Route::post('/shift-types/create', [AdminShiftTypeController::class, 'storeShiftType'])->name('admin.shift_types.store')->permission('shifts.update');
    Route::get('/shift-types/{id}/edit', [AdminShiftTypeController::class, 'editShiftType'])->name('admin.shift_types.edit')->permission('shifts.update');
    Route::post('/shift-types/{id}/edit', [AdminShiftTypeController::class, 'updateShiftType'])->name('admin.shift_types.update')->permission('shifts.update');
    Route::post('/shift-types/{id}/delete', [AdminShiftTypeController::class, 'deleteShiftType'])->name('admin.shift_types.delete')->permission('shifts.update');
    Route::post('/shift-types/{id}/toggle-store', [AdminShiftTypeController::class, 'toggleShiftTypeStore'])->name('admin.shift_types.toggle_store')->permission('shifts.update');

    // Shifts
    Route::get('/shifts', [AdminShiftController::class, 'shifts'])->name('admin.shifts')->permission('shifts.view');
    Route::get('/shifts/calendar', [AdminShiftController::class, 'shiftsCalendar'])->name('admin.shifts.calendar')->permission('shifts.view');
    Route::get('/shifts/timeline', [AdminShiftController::class, 'shiftsTimeline'])->name('admin.shifts.timeline')->permission('shifts.view');
    Route::get('/shifts/timeline/print', [AdminShiftController::class, 'shiftsTimelinePrint'])->name('admin.shifts.timeline.print')->permission('shifts.view');
    Route::get('/shifts/conflicts', [AdminShiftController::class, 'shiftConflicts'])->name('admin.shifts.conflicts')->permission('shifts.view');
    Route::post('/shifts/conflicts/resolve-newer', [AdminShiftController::class, 'resolveNewerConflict'])->name('admin.shifts.resolve_newer')->permission('shifts.update');

    Route::get('/shifts/import', [AdminShiftImportController::class, 'importShifts'])->name('admin.shifts.import')->permission('shifts.import');
    Route::post('/shifts/import', [AdminShiftImportController::class, 'processImport'])->name('admin.shifts.import.process')->permission('shifts.import');
    Route::post('/shifts/import/confirm', [AdminShiftImportController::class, 'confirmImport'])->name('admin.shifts.import.confirm')->permission('shifts.import');
    Route::get('/shifts/create', [AdminShiftController::class, 'createShift'])->name('admin.shifts.create')->permission('shifts.create');
    Route::get('/shifts/wage-preview', [AdminShiftController::class, 'wagePreview'])->name('admin.shifts.wage_preview')->permission('shifts.view');
    Route::post('/shifts/create', [AdminShiftController::class, 'storeShift'])->name('admin.shifts.store')->permission('shifts.create');
    Route::post('/shifts/quick', [AdminShiftController::class, 'quickShift'])->name('admin.shifts.quick')->permission('shifts.create');
    Route::post('/shifts/publish', [AdminShiftController::class, 'publishSchedule'])->name('admin.shifts.publish')->permission('shifts.update');
    Route::post('/shifts/copy-week', [AdminShiftWeekController::class, 'copyPreviousWeek'])->name('admin.shifts.copy_week')->permission('shifts.create');
    Route::post('/shifts/templates', [AdminShiftWeekController::class, 'saveTemplate'])->name('admin.shifts.templates.save')->permission('shifts.update');
    Route::post('/shifts/templates/{id}/apply', [AdminShiftWeekController::class, 'applyTemplate'])->name('admin.shifts.templates.apply')->permission('shifts.create');
    Route::post('/shifts/templates/{id}/delete', [AdminShiftWeekController::class, 'deleteTemplate'])->name('admin.shifts.templates.delete')->permission('shifts.update');
    // Feuilles de temps : shifts prévus comparés aux pointages, validation de la semaine
    Route::get('/timesheets', [AdminTimesheetController::class, 'index'])->name('admin.timesheets')->permission('shifts.view');
    Route::get('/timesheets/export', [AdminTimesheetController::class, 'export'])->name('admin.timesheets.export')->permission('shifts.view');
    Route::post('/timesheets/approve', [AdminTimesheetController::class, 'approve'])->name('admin.timesheets.approve')->permission('shifts.update');
    Route::post('/timesheets/reopen', [AdminTimesheetController::class, 'reopen'])->name('admin.timesheets.reopen')->permission('shifts.update');
    Route::post('/shifts/bulk-delete', [AdminShiftController::class, 'bulkDeleteShifts'])->name('admin.shifts.bulk_delete')->permission('shifts.delete');
    Route::get('/shifts/{id}/edit', [AdminShiftController::class, 'editShift'])->name('admin.shifts.edit')->permission('shifts.view');
    Route::post('/shifts/{id}/edit', [AdminShiftController::class, 'updateShift'])->name('admin.shifts.update')->permission('shifts.update');
    Route::post('/shifts/{id}/delete', [AdminShiftController::class, 'deleteShift'])->name('admin.shifts.delete')->permission('shifts.delete');
    Route::post('/shifts/{id}/move', [AdminShiftController::class, 'moveShift'])->name('admin.shifts.move')->permission('shifts.update');

    // Bourse aux shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-claim/ une fois installé (voir wiki : Architecture)

    // Demandes de congé : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeoff/ une fois installé (voir wiki : Architecture)

    // Échanges de shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-swap/ une fois installé (voir wiki : Architecture)

    // Pointage : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeclock/ une fois installé (voir wiki : Architecture)

    // Journal d'activité (unifié)
    Route::get('/activity', [ActivityController::class, 'index'])->name('admin.activity')->permission('stores.view');
    Route::get('/activity/export', [ActivityController::class, 'export'])->name('admin.activity.export')->permission('stores.view');
    Route::post('/activity/purge', [ActivityController::class, 'purge'])->middleware([OwnerOnlyMiddleware::class])->name('admin.activity.purge')->permission('public');


    // Diagnostic mail
    Route::get('/mail-test', [MailTestController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.mail_test')->permission('public');
    Route::post('/mail-test', [MailTestController::class, 'send'])->middleware([OwnerOnlyMiddleware::class])->name('admin.mail_test.send')->permission('public');

    // Notifications de l'instance : conservation, état des canaux, notification de test, clés du web push.
    Route::get('/notifications-settings', [NotificationSettingsController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.notifications_settings')->permission('public');
    Route::post('/notifications-settings', [NotificationSettingsController::class, 'save'])->middleware([OwnerOnlyMiddleware::class])->name('admin.notifications_settings.save')->permission('public');
    Route::post('/notifications-settings/test', [NotificationSettingsController::class, 'sendTest'])->middleware([OwnerOnlyMiddleware::class])->name('admin.notifications_settings.test')->permission('public');
    Route::post('/notifications-settings/vapid', [NotificationSettingsController::class, 'regenerateVapid'])->middleware([OwnerOnlyMiddleware::class])->name('admin.notifications_settings.vapid')->permission('public');

    // Agendas externes : identifiants Google de l'instance (Owner).
    Route::get('/calendar-settings', [CalendarSettingsController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.calendar_settings')->permission('public');
    Route::post('/calendar-settings', [CalendarSettingsController::class, 'save'])->middleware([OwnerOnlyMiddleware::class])->name('admin.calendar_settings.save')->permission('public');
    Route::post('/calendar-settings/test', [CalendarSettingsController::class, 'test'])->middleware([OwnerOnlyMiddleware::class])->name('admin.calendar_settings.test')->permission('public');

    // Sauvegardes
    Route::get('/backup', [BackupController::class, 'index'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup')->permission('public');
    Route::get('/backup/download', [BackupController::class, 'download'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup.download')->permission('public');
    Route::post('/backup/create', [BackupController::class, 'create'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup.create')->permission('public');
    Route::post('/backup/restore', [BackupController::class, 'restore'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup.restore')->permission('public');
    Route::post('/backup/delete', [BackupController::class, 'delete'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup.delete')->permission('public');
    Route::post('/backup/delete-all', [BackupController::class, 'deleteAll'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup.delete_all')->permission('public');
    Route::post('/backup/settings', [BackupController::class, 'saveSettings'])->middleware([OwnerOnlyMiddleware::class])->name('admin.backup.settings')->permission('public');

    // Réinitialisation de l'application ("danger zone")
    Route::post('/reset/prepare', [AppResetController::class, 'prepare'])->middleware([OwnerOnlyMiddleware::class])->name('admin.reset.prepare')->permission('public');
    Route::post('/reset/execute', [AppResetController::class, 'execute'])->middleware([OwnerOnlyMiddleware::class])->name('admin.reset.execute')->permission('public');

    // Mises à jour
    Route::get('/update', [BackupController::class, 'updatePage'])->middleware([OwnerOnlyMiddleware::class])->name('admin.update')->permission('public');
    Route::post('/update/apply', [BackupController::class, 'update'])->middleware([OwnerOnlyMiddleware::class])->name('admin.update.apply')->permission('public');
    Route::post('/update/stream', [BackupController::class, 'updateStream'])->middleware([OwnerOnlyMiddleware::class])->name('admin.update.stream')->permission('public');
    Route::post('/update/migrate', [BackupController::class, 'migrate'])->middleware([OwnerOnlyMiddleware::class])->name('admin.update.migrate')->permission('public');
    Route::post('/update/channel', [BackupController::class, 'saveChannel'])->middleware([OwnerOnlyMiddleware::class])->name('admin.update.channel')->permission('public');

    // Photos : bundle distribué hors monorepo, routes chargées depuis storage/bundles/store-photos/ une fois installé (voir wiki : Architecture)

    // Langues & traductions (Owner uniquement)
    Route::get('/languages', [LanguageController::class, 'index'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages')->permission('public');
    Route::post('/languages', [LanguageController::class, 'store'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages.store')->permission('public');
    Route::post('/languages/{code}/toggle-active', [LanguageController::class, 'toggleActive'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages.toggle_active')->permission('public');
    Route::post('/languages/{code}/delete', [LanguageController::class, 'destroy'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages.delete')->permission('public');
    Route::get('/languages/{code}/edit', [LanguageController::class, 'edit'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages.edit')->permission('public');
    Route::post('/languages/{code}/edit/save', [LanguageController::class, 'saveKey'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages.edit.save')->permission('public');
    Route::post('/languages/{code}/edit/delete', [LanguageController::class, 'deleteKey'])->middleware([OwnerOnlyMiddleware::class])->name('admin.languages.edit.delete')->permission('public');

    // Bundles (Owner uniquement)
    Route::get('/bundles', [BundleSettingsController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles')->permission('public');
    Route::post('/bundles', [BundleSettingsController::class, 'save'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.save')->permission('public');
    Route::get('/bundles/registries', [BundleMarketController::class, 'index'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.registries')->permission('public');
    Route::post('/bundles/registries', [BundleMarketController::class, 'store'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.registries.store')->permission('public');
    Route::post('/bundles/registries/{id}/delete', [BundleMarketController::class, 'destroy'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.registries.delete')->permission('public');
    Route::get('/bundles/market', [BundleMarketController::class, 'market'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.market')->permission('public');
    Route::post('/bundles/market/dry-run', [BundleMarketController::class, 'dryRun'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.market.dry-run')->permission('public');
    Route::post('/bundles/market/install', [BundleMarketController::class, 'install'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.market.install')->permission('public');
    Route::post('/bundles/market/install/stream', [BundleMarketController::class, 'installStream'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.market.install.stream')->permission('public');
    Route::post('/bundles/market/uninstall', [BundleMarketController::class, 'uninstall'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.market.uninstall')->permission('public');
    Route::post('/bundles/market/channel', [BundleMarketController::class, 'saveChannel'])->middleware([OwnerOnlyMiddleware::class])->name('admin.bundles.market.channel')->permission('public');

    // Licence (déblocage du plan payant, Owner uniquement)
    // Assistant de configuration ouvert par l'installateur : licence, puis bundles (voir SetupWizard).
    Route::get('/setup', [SetupWizardController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.setup')->permission('public');
    Route::post('/setup/license', [SetupWizardController::class, 'license'])->middleware([OwnerOnlyMiddleware::class])->name('admin.setup.license')->permission('public');
    Route::post('/setup/step/{step}', [SetupWizardController::class, 'step'])->middleware([OwnerOnlyMiddleware::class])->name('admin.setup.step')->permission('public');
    Route::post('/setup/finish', [SetupWizardController::class, 'finish'])->middleware([OwnerOnlyMiddleware::class])->name('admin.setup.finish')->permission('public');
    Route::post('/setup/bundles', [SetupWizardController::class, 'bundles'])->middleware([OwnerOnlyMiddleware::class])->name('admin.setup.bundles')->permission('public');
    Route::post('/setup/questions', [SetupWizardController::class, 'questions'])->middleware([OwnerOnlyMiddleware::class])->name('admin.setup.questions')->permission('public');
    Route::get('/license', [LicenseController::class, 'show'])->middleware([OwnerOnlyMiddleware::class])->name('admin.license')->permission('public');
    Route::post('/license/activate', [LicenseController::class, 'activate'])->middleware([OwnerOnlyMiddleware::class])->name('admin.license.activate')->permission('public');
    Route::post('/license/refresh', [LicenseController::class, 'refresh'])->middleware([OwnerOnlyMiddleware::class])->name('admin.license.refresh')->permission('public');
    Route::post('/license/deactivate', [LicenseController::class, 'deactivate'])->middleware([OwnerOnlyMiddleware::class])->name('admin.license.deactivate')->permission('public');

    // Rôles & permissions (Owner uniquement) — voir task/mermission.md
    Route::get('/roles', [AdminRoleController::class, 'roles'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles')->permission('public');
    Route::get('/roles/create', [AdminRoleController::class, 'createRole'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.create')->permission('public');
    Route::post('/roles/create', [AdminRoleController::class, 'storeRole'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.store')->permission('public');
    Route::get('/roles/{id}/edit', [AdminRoleController::class, 'editRole'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.edit')->permission('public');
    Route::post('/roles/{id}/edit', [AdminRoleController::class, 'updateRole'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.update')->permission('public');
    Route::post('/roles/{id}/delete', [AdminRoleController::class, 'deleteRole'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.delete')->permission('public');
    Route::post('/roles/{id}/holders', [AdminRoleController::class, 'addHolder'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.holders.add')->permission('public');
    Route::post('/roles/{id}/holders/{assignmentId}/delete', [AdminRoleController::class, 'removeHolder'])->middleware([OwnerOnlyMiddleware::class])->name('admin.roles.holders.delete')->permission('public');

});
