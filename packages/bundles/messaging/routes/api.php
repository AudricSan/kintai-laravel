<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\Messaging\Http\MessageController;

// Préfixe `api/v1/messaging`, noms `api.v1.messaging.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Libre-service : chacun ne voit que les conversations dont il est participant (sinon 404).
Route::whereNumber(['thread', 'message'])->group(function (): void {
    Route::get('threads', [MessageController::class, 'threads'])->name('threads.index');
    Route::get('unread', [MessageController::class, 'unread'])->name('unread');
    Route::post('threads', [MessageController::class, 'store'])->name('threads.store');
    Route::get('threads/{thread}', [MessageController::class, 'show'])->name('threads.show');
    Route::delete('threads/{thread}', [MessageController::class, 'destroy'])->name('threads.destroy');
    Route::get('threads/{thread}/messages', [MessageController::class, 'messages'])->name('messages.index');
    Route::post('threads/{thread}/messages', [MessageController::class, 'send'])->name('messages.store');
    Route::get('threads/{thread}/participants', [MessageController::class, 'participants'])->name('participants.index');
    Route::post('threads/{thread}/participants', [MessageController::class, 'addParticipant'])->name('participants.store');
    Route::delete('messages/{message}', [MessageController::class, 'destroyMessage'])->name('messages.destroy');
});
