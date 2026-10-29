<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\Messaging\Controllers\Web\MessageController;
use kintai\Bundles\Installed\Messaging\Controllers\Web\MessageStreamController;
use kintai\Bundles\Installed\Messaging\Controllers\Api\MessageController as ApiMessageController;


// =============================================================================
// Messaging — SSE Stream
// =============================================================================

Route::get('/messages/{id}/stream', [MessageStreamController::class, 'stream'])->middleware([AuthMiddleware::class])->name('messages.stream');

// =============================================================================
// Messaging — Routes Web Admin
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/messages/compose', [MessageController::class, 'messagesCompose'])->name('admin.messages.compose')->permission('messaging.send');
    Route::post('/messages', [MessageController::class, 'messagesSend'])->name('admin.messages.send')->permission('messaging.send');
    Route::get('/messages', [MessageController::class, 'messages'])->name('admin.messages')->permission('messaging.view');
    Route::get('/messages/{id}', [MessageController::class, 'messagesThread'])->name('admin.messages.thread')->permission('messaging.view');
    Route::post('/messages/{id}', [MessageController::class, 'messagesReply'])->name('admin.messages.reply')->permission('messaging.send');
    Route::post('/messages/{id}/delete', [MessageController::class, 'messagesDeleteThread'])->name('admin.messages.delete_thread')->permission('messaging.delete');
    Route::post('/messages/{id}/message/{mid}/delete', [MessageController::class, 'messagesDeleteMessage'])->name('admin.messages.delete_message')->permission('messaging.delete');
});

// =============================================================================
// Messaging — Routes Web Employé
// =============================================================================

Route::prefix('employee')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('/messages/compose', [MessageController::class, 'messagesCompose'])->name('employee.messages.compose');
    Route::post('/messages', [MessageController::class, 'messagesSend'])->name('employee.messages.send');
    Route::get('/messages', [MessageController::class, 'messages'])->name('employee.messages');
    Route::get('/messages/{id}', [MessageController::class, 'messagesThread'])->name('employee.messages.thread');
    Route::post('/messages/{id}', [MessageController::class, 'messagesReply'])->name('employee.messages.reply');
    Route::post('/messages/{id}/delete', [MessageController::class, 'messagesDeleteThread'])->name('employee.messages.delete_thread');
    Route::post('/messages/{id}/message/{mid}/delete', [MessageController::class, 'messagesDeleteMessage'])->name('employee.messages.delete_message');
});

// =============================================================================
// Messaging — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class])->group(function () {
    Route::get('/messages/threads', [ApiMessageController::class, 'listThreads'])->name('api.v1.messages.threads.index');
    Route::post('/messages/threads', [ApiMessageController::class, 'createThread'])->name('api.v1.messages.threads.store');
    Route::get('/messages/threads/{id}/messages', [ApiMessageController::class, 'listMessages'])->name('api.v1.messages.list');
    Route::post('/messages/threads/{id}/messages', [ApiMessageController::class, 'addMessage'])->name('api.v1.messages.store');
    Route::get('/messages/threads/{id}/participants', [ApiMessageController::class, 'listParticipants'])->name('api.v1.messages.participants.index');
    Route::post('/messages/threads/{id}/participants', [ApiMessageController::class, 'addParticipant'])->name('api.v1.messages.participants.store');
    Route::get('/messages/threads/{id}/participants/{user_id}', [ApiMessageController::class, 'getParticipant'])->name('api.v1.messages.participants.show');
    Route::get('/messages/threads/{id}', [ApiMessageController::class, 'getThread'])->name('api.v1.messages.threads.show');
    Route::delete('/messages/threads/{id}', [ApiMessageController::class, 'deleteThread'])->name('api.v1.messages.threads.destroy');
    Route::delete('/messages/{id}', [ApiMessageController::class, 'deleteMessage'])->name('api.v1.messages.destroy');
});
