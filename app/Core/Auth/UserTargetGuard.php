<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;

/**
 * Garde des routes API `/users/{user_id}/...` : charge l'utilisateur ciblé et vérifie que
 * l'appelant a le droit d'agir sur lui. Le middleware d'API ne voit pas le magasin de la
 * cible (aucun `store_id` dans l'URL) : sans ce contrôle, une permission accordée sur un
 * seul magasin suffisait pour lire ou modifier les jetons et préférences de tout le monde.
 */
final class UserTargetGuard
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @return array Ligne de l'utilisateur ciblé
     * @throws NotFoundException Si la cible n'existe pas
     * @throws \kintai\Core\Exceptions\ForbiddenException Si l'accès est refusé
     */
    public function require(Request $request, int $userId, string $permissionKey): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }

        $storeIds = array_map(
            fn(array $m): int => (int) $m['store_id'],
            $this->storeUsers->findByUser($userId)
        );
        $this->permissions->requireUserAccess($request->getAttribute('auth_user') ?? [], $permissionKey, $userId, $storeIds);

        return $user;
    }
}
