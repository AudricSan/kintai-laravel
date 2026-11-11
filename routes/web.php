<?php

use App\Backups\BackupManager;
use App\Http\Controllers\ActivityExportController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\NotificationOpenController;
use App\Http\Controllers\NotificationUnsubscribeController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\TimelinePrintController;
use App\Http\Middleware\EnsureNotInstalled;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Assistant d'installation : 404 une fois Kintai installé (verrou `storage/app/installed`). Pas de limiteur de débit :
// il s'appuie sur le cache, et il n'y a pas encore de base de données.
Route::middleware(EnsureNotInstalled::class)->prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'show'])->name('show');
    Route::post('/', [InstallController::class, 'store'])->name('store');
});

// Désabonnement e-mail : sans connexion, protégé par la signature du lien (sans expiration, comme l'ancienne version).
// Le POST sert aussi au « one-click » des clients de messagerie (en-tête List-Unsubscribe-Post), exclu du CSRF.
Route::get('notifications/unsubscribe', [NotificationUnsubscribeController::class, 'show'])->name('notifications.unsubscribe');
Route::post('notifications/unsubscribe', [NotificationUnsubscribeController::class, 'confirm'])->name('notifications.unsubscribe.confirm');

// PWA : service worker (push, clic sur une notification) et manifeste, publics.
Route::get('sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

Route::middleware(['auth'])->group(function () {
    // `audience` : « auto » = tableau gérant pour un gérant ou un Owner, tableau employé sinon.
    Route::livewire('dashboard', 'pages::dashboard.show')->defaults('audience', 'auto')->name('dashboard');
    Route::livewire('dashboard/employee', 'pages::dashboard.show')->defaults('audience', 'employee')->name('dashboard.employee');

    Route::livewire('requests', 'pages::requests.index')->name('requests.index');

    Route::livewire('notifications', 'pages::notifications.index')->name('notifications.index');
    Route::get('notifications/{notification}/open', NotificationOpenController::class)->name('notifications.open');

    // Abonnements Web Push du navigateur de l'utilisateur connecté (JSON, CSRF de la session).
    Route::get('push/status', [PushSubscriptionController::class, 'status'])->name('push.status');
    Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->name('push.subscriptions.store');
    Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');

    // Exports : droits décidés par jeu de données et par magasin (ExportAccess), chacun exporte au moins ses données.
    // Documentation intégrée (wiki GitHub synchronisé) et signalement de problème : ouverts à tout utilisateur connecté.
    // Sans nom de page, `/docs` renvoie vers la première page (ou dit que la documentation n'est pas encore là).
    Route::livewire('docs/{page?}', 'pages::docs.show')->name('docs.show');
    Route::livewire('support/report-issue', 'pages::support.report-issue')->name('support.report-issue');

    Route::livewire('exports', 'pages::exports.index')->name('exports.index');
    Route::get('exports/download', ExportController::class)->middleware('throttle:30,1')->name('exports.download');

    Route::get('avatar/{user}', [AvatarController::class, 'show'])->name('avatar.show');

    Route::prefix('employees')->group(function () {
        Route::livewire('/', 'pages::employees.index')->middleware('can:employees.view')->name('employees.index');
        Route::livewire('create', 'pages::employees.create')->middleware('can:employees.create')->name('employees.create');
        Route::livewire('{user}/edit', 'pages::employees.edit')->middleware('can:employees.view')->name('employees.edit');
    });

    Route::prefix('stores')->group(function () {
        Route::livewire('/', 'pages::stores.index')->middleware('can:stores.view')->name('stores.index');
        Route::livewire('create', 'pages::stores.form')->middleware('can:stores.create')->name('stores.create');
        Route::livewire('{store}/edit', 'pages::stores.form')->middleware('can:stores.view')->name('stores.edit');
    });

    Route::prefix('shift-types')->group(function () {
        Route::livewire('/', 'pages::shift-types.index')->middleware('can:shifts.view')->name('shift-types.index');
        Route::livewire('create', 'pages::shift-types.form')->middleware('can:shifts.update')->name('shift-types.create');
        Route::livewire('{shiftType}/edit', 'pages::shift-types.form')->middleware('can:shifts.update')->name('shift-types.edit');
    });

    Route::prefix('shifts')->group(function () {
        Route::livewire('/', 'pages::shifts.index')->middleware('can:shifts.view')->name('shifts.index');
        Route::livewire('calendar', 'pages::shifts.calendar')->middleware('can:shifts.view')->name('shifts.calendar');
        Route::livewire('timeline', 'pages::shifts.timeline')->middleware('can:shifts.view')->name('shifts.timeline');
        Route::get('timeline/print', TimelinePrintController::class)->middleware('can:shifts.view')->name('shifts.timeline.print');
        Route::livewire('create', 'pages::shifts.form')->middleware('can:shifts.create')->name('shifts.create');
        Route::livewire('conflicts', 'pages::shifts.conflicts')->middleware('can:shifts.view')->name('shifts.conflicts');
        Route::livewire('{shift}/edit', 'pages::shifts.form')->middleware('can:shifts.update')->name('shifts.edit')->withTrashed();
    });

    Route::prefix('my-shifts')->group(function () {
        Route::livewire('/', 'pages::my-shifts.index')->name('my-shifts.index');
        Route::livewire('calendar', 'pages::my-shifts.calendar')->name('my-shifts.calendar');
        Route::livewire('week', 'pages::my-shifts.week')->name('my-shifts.week');
        Route::livewire('day', 'pages::my-shifts.day')->name('my-shifts.day');
    });

    Route::prefix('activity')->middleware('can:stores.view')->group(function () {
        Route::livewire('/', 'pages::activity.index')->name('activity.index');
        Route::get('export', ActivityExportController::class)->name('activity.export');
    });

    // Réglages de l'instance (Owner seulement).
    Route::livewire('admin/settings', 'pages::admin.settings')->middleware('can:manage-settings')->name('settings.app');

    // Sauvegardes et réinitialisation des données (Owner seulement). Le téléchargement n'accepte qu'un nom d'archive au motif strict.
    Route::middleware('can:manage-settings')->group(function () {
        Route::livewire('admin/backups', 'pages::admin.backups')->name('backups.index');
        Route::get('admin/backups/{backup}/download', BackupDownloadController::class)
            ->where('backup', BackupManager::ROUTE_PATTERN)
            ->middleware('throttle:20,1')
            ->name('backups.download');
        Route::livewire('admin/data-reset', 'pages::admin.data-reset')->name('data-reset.index');
        // Bundles installés : activation et désactivation.
        Route::livewire('admin/bundles', 'pages::admin.bundles')->name('bundles.index');
        // Tâches planifiées et jetons de l'appel HTTP `/cron/run` (hébergement sans terminal).
        Route::livewire('admin/scheduled-tasks', 'pages::admin.scheduled-tasks')->name('scheduled-tasks.index');
    });

    Route::prefix('roles')->middleware('can:manage-roles')->group(function () {
        Route::livewire('/', 'pages::roles.index')->name('roles.index');
        Route::livewire('create', 'pages::roles.form')->name('roles.create');
        Route::livewire('{role}/edit', 'pages::roles.form')->name('roles.edit');
    });
});

require __DIR__.'/settings.php';
