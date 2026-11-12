<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\NotificationResource;
use App\Notifications\NotificationInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Cloche de l'utilisateur authentifié : ressource strictement personnelle, aucun identifiant d'utilisateur dans l'URL.
 * La notification d'un autre utilisateur est introuvable (404).
 */
class NotificationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->actor($request);
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $query = filter_var($request->query('unread'), FILTER_VALIDATE_BOOL) ? $user->unreadNotifications() : $user->notifications();

        return NotificationResource::collection($query->paginate($perPage))
            ->additional(['unread_count' => app(NotificationInbox::class)->unreadCount($user)]);
    }

    public function show(Request $request, string $notification): NotificationResource
    {
        return new NotificationResource($this->own($request, $notification));
    }

    public function markRead(Request $request, string $notification): NotificationResource
    {
        $record = $this->own($request, $notification);
        $record->markAsRead();

        return new NotificationResource($record->refresh());
    }

    public function markAllRead(Request $request, NotificationInbox $inbox): Response
    {
        $inbox->markAllRead($this->actor($request));

        return response()->noContent();
    }

    public function destroy(Request $request, string $notification): Response
    {
        $this->own($request, $notification)->delete();

        return response()->noContent();
    }

    private function own(Request $request, string $id): DatabaseNotification
    {
        return $this->actor($request)->notifications()->whereKey($id)->firstOrFail();
    }
}
