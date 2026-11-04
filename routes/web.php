<?php

use App\Http\Controllers\AvatarController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('avatar/{user}', [AvatarController::class, 'show'])->name('avatar.show');

    Route::prefix('employees')->group(function () {
        Route::livewire('/', 'pages::employees.index')->middleware('can:employees.view')->name('employees.index');
        Route::livewire('create', 'pages::employees.create')->middleware('can:employees.create')->name('employees.create');
        Route::livewire('{user}/edit', 'pages::employees.edit')->middleware('can:employees.view')->name('employees.edit');
    });

    Route::prefix('roles')->middleware('can:manage-roles')->group(function () {
        Route::livewire('/', 'pages::roles.index')->name('roles.index');
        Route::livewire('create', 'pages::roles.form')->name('roles.create');
        Route::livewire('{role}/edit', 'pages::roles.form')->name('roles.edit');
    });
});

require __DIR__.'/settings.php';
