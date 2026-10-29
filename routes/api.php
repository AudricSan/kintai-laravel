<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Middleware\LoginThrottleMiddleware;
use kintai\UI\Controller\Api\V1\ActivityController as ApiActivityController;
use kintai\UI\Controller\Api\V1\AuthController as ApiAuthController;
use kintai\UI\Controller\Api\V1\AvailabilityController as ApiAvailabilityController;
use kintai\UI\Controller\Api\V1\IcalTokenController as ApiIcalTokenController;
use kintai\UI\Controller\Api\V1\NotificationController as ApiNotificationController;
use kintai\UI\Controller\Api\V1\NotificationPreferenceController as ApiNotificationPreferenceController;
use kintai\UI\Controller\Api\V1\PushTokenController as ApiPushTokenController;
use kintai\UI\Controller\Api\V1\ShiftController as ApiShiftController;
use kintai\UI\Controller\Api\V1\ShiftTypeController as ApiShiftTypeController;
use kintai\UI\Controller\Api\V1\StoreController as ApiStoreController;
use kintai\UI\Controller\Api\V1\StoreUserController as ApiStoreUserController;
use kintai\UI\Controller\Api\V1\UserController as ApiUserController;
use kintai\UI\Controller\Api\V1\UserPrefsController as ApiUserPrefsController;
use kintai\UI\Controller\Api\V1\UserShiftRateController as ApiUserShiftRateController;

// =============================================================================
// API v1
// =============================================================================

// --- Routes publiques ---
Route::get('/api/v1/ping', [ApiAuthController::class, 'ping'])->name('api.v1.ping');
Route::post('/api/v1/auth/login', [ApiAuthController::class, 'login'])->middleware([LoginThrottleMiddleware::class])->name('api.v1.auth.login');

// --- Routes protégées par token Bearer ---
Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {

    // Auth
    Route::post('/auth/logout', [ApiAuthController::class, 'logout'])->name('api.v1.auth.logout')->permission('public');
    Route::get('/auth/me', [ApiAuthController::class, 'me'])->name('api.v1.auth.me')->permission('public');
    Route::get('/auth/tokens', [ApiAuthController::class, 'listTokens'])->name('api.v1.auth.tokens')->permission('public');
    Route::delete('/auth/tokens/{id}', [ApiAuthController::class, 'revokeToken'])->name('api.v1.auth.tokens.revoke')->permission('public');

    // Users
    Route::get('/users', [ApiUserController::class, 'index'])->name('api.v1.users.index')->permission('employees.view');
    Route::post('/users', [ApiUserController::class, 'store'])->name('api.v1.users.store')->permission('employees.create');
    Route::get('/users/{id}', [ApiUserController::class, 'show'])->name('api.v1.users.show')->permission(['perm' => 'employees.view', 'self' => 'id']);
    Route::put('/users/{id}', [ApiUserController::class, 'update'])->name('api.v1.users.update')->permission('employees.update');
    Route::delete('/users/{id}', [ApiUserController::class, 'destroy'])->name('api.v1.users.destroy')->permission('employees.delete');

    // Users — prefs, rates, ical-tokens
    Route::get('/users/{user_id}/dashboard-prefs', [ApiUserPrefsController::class, 'getDashboardPrefs'])->name('api.v1.users.dashboard_prefs.get')->permission(['perm' => 'employees.view', 'self' => 'user_id']);
    Route::put('/users/{user_id}/dashboard-prefs', [ApiUserPrefsController::class, 'saveDashboardPrefs'])->name('api.v1.users.dashboard_prefs.save')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::get('/users/{user_id}/nav-prefs', [ApiUserPrefsController::class, 'getNavPrefs'])->name('api.v1.users.nav_prefs.get')->permission(['perm' => 'employees.view', 'self' => 'user_id']);
    Route::put('/users/{user_id}/nav-prefs', [ApiUserPrefsController::class, 'saveNavPrefs'])->name('api.v1.users.nav_prefs.save')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::get('/users/{user_id}/rates', [ApiUserShiftRateController::class, 'index'])->name('api.v1.users.rates.index')->permission(['perm' => 'payroll.view', 'self' => 'user_id']);
    Route::post('/users/{user_id}/rates', [ApiUserShiftRateController::class, 'store'])->name('api.v1.users.rates.store')->permission('employees.update');
    Route::get('/users/{user_id}/rates/{id}', [ApiUserShiftRateController::class, 'show'])->name('api.v1.users.rates.show')->permission(['perm' => 'payroll.view', 'self' => 'user_id']);
    Route::put('/users/{user_id}/rates/{id}', [ApiUserShiftRateController::class, 'update'])->name('api.v1.users.rates.update')->permission('employees.update');
    Route::delete('/users/{user_id}/rates/{id}', [ApiUserShiftRateController::class, 'destroy'])->name('api.v1.users.rates.destroy')->permission('employees.update');
    // Users — jetons d'appareil push (notifications mobiles, FCM)
    Route::get('/users/{user_id}/notification-preferences', [ApiNotificationPreferenceController::class, 'show'])->name('api.v1.users.notification_preferences.show')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::put('/users/{user_id}/notification-preferences', [ApiNotificationPreferenceController::class, 'update'])->name('api.v1.users.notification_preferences.update')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::post('/users/{user_id}/push-tokens', [ApiPushTokenController::class, 'store'])->name('api.v1.users.push_tokens.store')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::delete('/users/{user_id}/push-tokens', [ApiPushTokenController::class, 'destroy'])->name('api.v1.users.push_tokens.destroy')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::get('/users/{user_id}/ical-tokens', [ApiIcalTokenController::class, 'index'])->name('api.v1.users.ical_tokens.index')->permission(['perm' => 'employees.view', 'self' => 'user_id']);
    Route::post('/users/{user_id}/ical-tokens', [ApiIcalTokenController::class, 'store'])->name('api.v1.users.ical_tokens.store')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::post('/users/{user_id}/ical-tokens/{store_id}/regenerate', [ApiIcalTokenController::class, 'regenerate'])->name('api.v1.users.ical_tokens.regenerate')->permission(['perm' => 'employees.update', 'self' => 'user_id']);
    Route::get('/users/{user_id}/ical-tokens/{store_id}', [ApiIcalTokenController::class, 'show'])->name('api.v1.users.ical_tokens.show')->permission(['perm' => 'employees.view', 'self' => 'user_id']);
    Route::delete('/users/{user_id}/ical-tokens/{store_id}', [ApiIcalTokenController::class, 'destroy'])->name('api.v1.users.ical_tokens.destroy')->permission(['perm' => 'employees.update', 'self' => 'user_id']);

    // Stores
    Route::get('/stores', [ApiStoreController::class, 'index'])->name('api.v1.stores.index')->permission('stores.view');
    Route::post('/stores', [ApiStoreController::class, 'store'])->name('api.v1.stores.store')->permission('stores.create');
    Route::get('/stores/{id}', [ApiStoreController::class, 'show'])->name('api.v1.stores.show')->permission('stores.view');
    Route::put('/stores/{id}', [ApiStoreController::class, 'update'])->name('api.v1.stores.update')->permission('stores.update');
    Route::delete('/stores/{id}', [ApiStoreController::class, 'destroy'])->name('api.v1.stores.destroy')->permission('stores.delete');

    // Stores — membres
    Route::get('/stores/{store_id}/members', [ApiStoreUserController::class, 'index'])->name('api.v1.store_members.index')->permission('employees.view');
    Route::post('/stores/{store_id}/members', [ApiStoreUserController::class, 'store'])->name('api.v1.store_members.store')->permission('employees.update');
    Route::get('/stores/{store_id}/members/{id}', [ApiStoreUserController::class, 'show'])->name('api.v1.store_members.show')->permission('employees.view');
    Route::put('/stores/{store_id}/members/{id}', [ApiStoreUserController::class, 'update'])->name('api.v1.store_members.update')->permission('employees.update');
    Route::delete('/stores/{store_id}/members/{id}', [ApiStoreUserController::class, 'destroy'])->name('api.v1.store_members.destroy')->permission('employees.update');

    // Shift types
    Route::get('/shift-types', [ApiShiftTypeController::class, 'index'])->name('api.v1.shift_types.index')->permission('shifts.view');
    Route::post('/shift-types', [ApiShiftTypeController::class, 'store'])->name('api.v1.shift_types.store')->permission('shifts.update');
    Route::get('/shift-types/{id}', [ApiShiftTypeController::class, 'show'])->name('api.v1.shift_types.show')->permission('shifts.view');
    Route::put('/shift-types/{id}', [ApiShiftTypeController::class, 'update'])->name('api.v1.shift_types.update')->permission('shifts.update');
    Route::delete('/shift-types/{id}', [ApiShiftTypeController::class, 'destroy'])->name('api.v1.shift_types.destroy')->permission('shifts.update');

    // Shifts
    Route::get('/shifts', [ApiShiftController::class, 'index'])->name('api.v1.shifts.index')->permission(['perm' => 'shifts.view', 'self' => 'user_id']);
    Route::post('/shifts', [ApiShiftController::class, 'store'])->name('api.v1.shifts.store')->permission('shifts.create');
    Route::get('/shifts/{id}', [ApiShiftController::class, 'show'])->name('api.v1.shifts.show')->permission('shifts.view');
    Route::put('/shifts/{id}', [ApiShiftController::class, 'update'])->name('api.v1.shifts.update')->permission('shifts.update');
    Route::delete('/shifts/{id}', [ApiShiftController::class, 'destroy'])->name('api.v1.shifts.destroy')->permission('shifts.delete');

    // Disponibilités
    Route::get('/availabilities', [ApiAvailabilityController::class, 'index'])->name('api.v1.availabilities.index')->permission('shifts.view');
    Route::post('/availabilities', [ApiAvailabilityController::class, 'store'])->name('api.v1.availabilities.store')->permission('shifts.update');
    Route::get('/availabilities/{id}', [ApiAvailabilityController::class, 'show'])->name('api.v1.availabilities.show')->permission('shifts.view');
    Route::put('/availabilities/{id}', [ApiAvailabilityController::class, 'update'])->name('api.v1.availabilities.update')->permission('shifts.update');
    Route::delete('/availabilities/{id}', [ApiAvailabilityController::class, 'destroy'])->name('api.v1.availabilities.destroy')->permission('shifts.update');

    // Demandes de congé : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeoff/ une fois installé (voir wiki : Architecture)

    // Échanges de shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-swap/ une fois installé (voir wiki : Architecture)

    // Pointage : bundle distribué hors monorepo, routes chargées depuis storage/bundles/timeclock/ une fois installé (voir wiki : Architecture)

    // Bourse aux shifts : bundle distribué hors monorepo, routes chargées depuis storage/bundles/shift-claim/ une fois installé (voir wiki : Architecture)

    // Notifications
    Route::post('/notifications/read-all', [ApiNotificationController::class, 'markAllRead'])->name('api.v1.notifications.read_all')->permission('public');
    Route::get('/notifications', [ApiNotificationController::class, 'index'])->name('api.v1.notifications.index')->permission('public');
    Route::get('/notifications/{id}', [ApiNotificationController::class, 'show'])->name('api.v1.notifications.show')->permission('public');
    Route::post('/notifications/{id}/read', [ApiNotificationController::class, 'markRead'])->name('api.v1.notifications.read')->permission('public');
    Route::delete('/notifications/{id}', [ApiNotificationController::class, 'destroy'])->name('api.v1.notifications.destroy')->permission('public');


    // Journal d'activité
    Route::get('/activity', [ApiActivityController::class, 'index'])->name('api.v1.activity.index')->permission('stores.view');

});
