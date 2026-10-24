<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Api\Paginator;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Notifications du porteur du token — ressource strictement personnelle :
 * chaque endpoint opère sur les notifications de l'utilisateur authentifié
 * uniquement (l'ancien paramètre `?user_id=` qui permettait de lire ou
 * marquer les notifications d'un autre compte a été supprimé).
 */
final class NotificationController
{
    public function __construct(private readonly NotificationRepositoryInterface $notifications) {}

    /** GET /api/v1/notifications?limit=20 */
    public function index(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);

        [$page, $limit] = Paginator::params($request);
        $items = $userId > 0 ? $this->notifications->findByUser($userId, 1000) : [];

        return Response::json(Paginator::paginate(array_map([$this, 'present'], $items), $page, $limit));
    }

    /** GET /api/v1/notifications/{id} */
    public function show(Request $request): Response
    {
        return Response::json($this->present($this->findOwn($request)));
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
        $this->notifications->markRead((int) $item['id'], (int) $item['user_id']);
        return Response::empty();
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        if ($userId > 0) {
            $this->notifications->markAllRead($userId);
        }
        return Response::empty();
    }

    /** DELETE /api/v1/notifications/{id} */
    public function destroy(Request $request): Response
    {
        $item = $this->findOwn($request);
        $this->notifications->delete((int) $item['id']);
        return Response::empty();
    }

    /**
     * Charge la notification ciblée en vérifiant qu'elle appartient bien au
     * porteur du token. Une notification d'autrui est traitée comme
     * inexistante (404) pour ne pas révéler son existence.
     */
    private function findOwn(Request $request): array
    {
        $item   = $this->notifications->findById((int) $request->param('id'));
        $userId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        if ($item === null || (int) ($item['user_id'] ?? 0) !== $userId) {
            throw new NotFoundException(__('error_notification_not_found'));
        }
        return $item;
    }
}
