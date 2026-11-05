<?php

use App\Http\Controllers\Api\V1\AccessTokenController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\DashboardPrefsController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\IcalTokenController;
use App\Http\Controllers\Api\V1\MeExportController;
use App\Http\Controllers\Api\V1\NavigationPrefsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\ShiftConflictController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\ShiftTypeController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StoreMemberController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRateController;
use App\Http\Middleware\EnsureApiAccountIsActive;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (`/api/v1`)
|--------------------------------------------------------------------------
|
| Contrat public et stable pour l'application mobile. Sans état : aucun middleware `web`, donc ni session ni cookie ;
| authentification par jeton « Bearer » (Sanctum). Erreurs JSON uniformes (`ApiErrorRenderer`), 300 requêtes par minute
| par jeton (par IP sans jeton). Les routes sont nommées `api.v1.*`. Documentation : docs/API-V1.md.
|
| Les endpoints d'un utilisateur (`/users/{user}/...`) valent pour soi-même ou, pour un gérant, pour un employé qu'il peut gérer.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware([SetApiLocale::class, 'throttle:api'])->group(function (): void {
    // Public.
    Route::get('ping', PingController::class)->name('ping');
    // Mêmes limiteurs que la connexion web : par identifiant + magasin + IP, et par IP.
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

    // Authentifié par jeton ; un compte désactivé perd ses jetons.
    Route::middleware(['auth:sanctum', EnsureApiAccountIsActive::class])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::get('auth/tokens', [AccessTokenController::class, 'index'])->name('auth.tokens');
        Route::delete('auth/tokens/{token}', [AccessTokenController::class, 'destroy'])->whereNumber('token')->name('auth.tokens.revoke');

        // Cloche : toujours celle de l'utilisateur du jeton.
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

        Route::prefix('me')->name('me.')->group(function (): void {
            Route::get('schedule-export', [MeExportController::class, 'schedule'])->name('schedule_export');
            Route::get('data-export', [MeExportController::class, 'data'])->name('data_export');
        });

        // Utilisateurs, taux personnels, disponibilités.
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('user')->name('users.reset_password');
        Route::put('users/{user}/availabilities/{store}', [AvailabilityController::class, 'update'])->whereNumber(['user', 'store'])->name('users.availabilities.update');

        Route::prefix('users/{user}/rates')->whereNumber(['user', 'rate'])->name('users.rates.')->group(function (): void {
            Route::get('/', [UserRateController::class, 'index'])->name('index');
            Route::post('/', [UserRateController::class, 'store'])->name('store');
            Route::get('{rate}', [UserRateController::class, 'show'])->name('show');
            Route::put('{rate}', [UserRateController::class, 'update'])->name('update');
            Route::delete('{rate}', [UserRateController::class, 'destroy'])->name('destroy');
        });

        // Magasins et membres.
        Route::apiResource('stores', StoreController::class)->parameters(['stores' => 'store'])->whereNumber('store')->names('stores');
        Route::apiResource('stores.members', StoreMemberController::class)->parameters(['stores' => 'store', 'members' => 'user'])->whereNumber(['store', 'user'])->names('stores.members');

        // Types de shift.
        Route::apiResource('shift-types', ShiftTypeController::class)->parameters(['shift-types' => 'type'])->whereNumber('type')->names('shift_types');
        Route::post('shift-types/{type}/stores/{store}', [ShiftTypeController::class, 'attach'])->whereNumber(['type', 'store'])->name('shift_types.stores.attach');
        Route::delete('shift-types/{type}/stores/{store}', [ShiftTypeController::class, 'detach'])->whereNumber(['type', 'store'])->name('shift_types.stores.detach');

        // Shifts (les routes fixes avant `shifts/{shift}`).
        Route::get('shifts/conflicts', [ShiftConflictController::class, 'index'])->name('shifts.conflicts');
        Route::post('shifts/conflicts/resolve', [ShiftConflictController::class, 'resolve'])->name('shifts.conflicts.resolve');
        Route::post('shifts/bulk-delete', [ShiftController::class, 'bulkDelete'])->name('shifts.bulk_delete');
        Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
        Route::prefix('shifts/{shift}')->whereNumber('shift')->name('shifts.')->group(function (): void {
            Route::get('/', [ShiftController::class, 'show'])->name('show');
            Route::put('/', [ShiftController::class, 'update'])->name('update');
            Route::delete('/', [ShiftController::class, 'destroy'])->name('destroy');
            Route::post('move', [ShiftController::class, 'move'])->name('move');
            Route::post('restore', [ShiftController::class, 'restore'])->name('restore');
            Route::post('replace', [ShiftController::class, 'replace'])->name('replace');
        });

        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
        Route::get('exports/{dataset}', [ExportController::class, 'download'])->name('exports.download');

        Route::get('availabilities', [AvailabilityController::class, 'index'])->name('availabilities.index');
        Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

        Route::prefix('users/{user}')->whereNumber('user')->name('users.')->group(function (): void {
            Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification_preferences.show');
            Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification_preferences.update');

            Route::post('push-tokens', [PushTokenController::class, 'store'])->name('push_tokens.store');
            Route::delete('push-tokens', [PushTokenController::class, 'destroy'])->name('push_tokens.destroy');

            Route::get('ical-tokens', [IcalTokenController::class, 'index'])->name('ical_tokens.index');
            Route::post('ical-tokens', [IcalTokenController::class, 'store'])->name('ical_tokens.store');
            Route::post('ical-tokens/{store}/regenerate', [IcalTokenController::class, 'regenerate'])->whereNumber('store')->name('ical_tokens.regenerate');
            Route::get('ical-tokens/{store}', [IcalTokenController::class, 'show'])->whereNumber('store')->name('ical_tokens.show');
            Route::delete('ical-tokens/{store}', [IcalTokenController::class, 'destroy'])->whereNumber('store')->name('ical_tokens.destroy');

            Route::get('dashboard-prefs', [DashboardPrefsController::class, 'show'])->name('dashboard_prefs.show');
            Route::put('dashboard-prefs', [DashboardPrefsController::class, 'update'])->name('dashboard_prefs.update');
            Route::get('nav-prefs', [NavigationPrefsController::class, 'show'])->name('nav_prefs.show');
            Route::put('nav-prefs', [NavigationPrefsController::class, 'update'])->name('nav_prefs.update');
        });
    });
});
