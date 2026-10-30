<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Paginator;
use App\Exceptions\NotFoundException;
use App\Models\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notifications du porteur du token — ressource strictement personnelle :
 * chaque endpoint opère sur les notifications de l'utilisateur authentifié
 * uniquement (l'ancien paramètre `?user_id=` qui permettait de lire ou
 * marquer les notifications d'un autre compte a été supprimé).
 */
final class NotificationController
{
    /** GET /api/v1/notifications?limit=20 */
    public function index(Request $request): Response
    {
        $userId = (int) ($request->attributes->get('auth_user')['id'] ?? 0);

        [$page, $limit] = Paginator::params($request);
        $items = $userId > 0 ? Notification::findByUser($userId, 1000) : [];

        return response()->json(Paginator::paginate(array_map([$this, 'present'], $items), $page, $limit));
    }

    /** GET /api/v1/notifications/{id} */
    public function show(Request $request): Response
    {
        return response()->json($this->present($this->findOwn($request)));
    }

    /** Ajoute le titre et le texte traduits dans la langue courante (body_key + params, sinon l'instantané body). */
    private function present(array $item): array
    {
        return array_merge($item, [
            'title' => notification_type_label((string) ($item['type'] ?? '')),
            'body'  => notification_body($item),
        ]);
    }

    /** POST /api/v1/notifications/{id}/read */
    public function markRead(Request $request): Response
    {
        $item = $this->findOwn($request);
        Notification::markRead((int) $item['id'], (int) $item['user_id']);
        return response()->noContent();
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request): Response
    {
        $userId = (int) ($request->attributes->get('auth_user')['id'] ?? 0);
        if ($userId > 0) {
            Notification::markAllRead($userId);
        }
        return response()->noContent();
    }

    /** DELETE /api/v1/notifications/{id} */
    public function destroy(Request $request): Response
    {
        $item = $this->findOwn($request);
        Notification::remove((int) $item['id']);
        return response()->noContent();
    }

    /**
     * Charge la notification ciblée en vérifiant qu'elle appartient bien au
     * porteur du token. Une notification d'autrui est traitée comme
     * inexistante (404) pour ne pas révéler son existence.
     */
    private function findOwn(Request $request): array
    {
        $item   = Notification::findById((int) $request->route('id'));
        $userId = (int) ($request->attributes->get('auth_user')['id'] ?? 0);
        if ($item === null || (int) ($item['user_id'] ?? 0) !== $userId) {
            throw new NotFoundException(__('error_notification_not_found'));
        }
        return $item;
    }
}
