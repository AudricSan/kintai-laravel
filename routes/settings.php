<?php

use App\Http\Controllers\PersonalDataExportController;
use App\Http\Controllers\ScheduleExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/navigation', 'pages::settings.navigation')->name('navigation.edit');

    Route::livewire('settings/notifications', 'pages::settings.notifications')->name('notification-preferences.edit');

    Route::livewire('settings/calendar', 'pages::settings.calendar')->name('calendar.edit');
    Route::get('settings/schedule-export', ScheduleExportController::class)->middleware('throttle:30,1')->name('schedule-export.download');

    Route::livewire('settings/data', 'pages::settings.data')->name('data.edit');
    Route::get('settings/data-export', PersonalDataExportController::class)->middleware('throttle:30,1')->name('data-export.download');

    Route::livewire('settings/availability', 'pages::settings.availability')->name('availability.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
