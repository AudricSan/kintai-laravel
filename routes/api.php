<?php

use App\Http\Controllers\Api\V1\AccessTokenController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardPrefsController;
use App\Http\Controllers\Api\V1\IcalTokenController;
use App\Http\Controllers\Api\V1\MeExportController;
use App\Http\Controllers\Api\V1\NavigationPrefsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\PushTokenController;
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
