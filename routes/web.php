<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\TimelinePrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    // `audience` : « auto » = tableau gérant pour un gérant ou un Owner, tableau employé sinon.
    Route::livewire('dashboard', 'pages::dashboard.show')->defaults('audience', 'auto')->name('dashboard');
    Route::livewire('dashboard/employee', 'pages::dashboard.show')->defaults('audience', 'employee')->name('dashboard.employee');

    Route::livewire('requests', 'pages::requests.index')->name('requests.index');

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

    Route::prefix('roles')->middleware('can:manage-roles')->group(function () {
        Route::livewire('/', 'pages::roles.index')->name('roles.index');
        Route::livewire('create', 'pages::roles.form')->name('roles.create');
        Route::livewire('{role}/edit', 'pages::roles.form')->name('roles.edit');
    });
});

require __DIR__.'/settings.php';
