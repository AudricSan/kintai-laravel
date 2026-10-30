<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\NotFoundException;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Garde des routes API `/users/{user_id}/...` : charge l'utilisateur ciblé et vérifie que
 * l'appelant a le droit d'agir sur lui. Le middleware d'API ne voit pas le magasin de la
 * cible (aucun `store_id` dans l'URL) : sans ce contrôle, une permission accordée sur un
 * seul magasin suffisait pour lire ou modifier les jetons et préférences de tout le monde.
 */
final class UserTargetGuard
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @return array Ligne de l'utilisateur ciblé
     * @throws NotFoundException Si la cible n'existe pas
     * @throws \App\Exceptions\ForbiddenException Si l'accès est refusé
     */
    public function require(Request $request, int $userId, string $permissionKey): array
    {
        $user = User::findById($userId);
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }

        $storeIds = array_map(
            fn(array $m): int => (int) $m['store_id'],
            StoreUser::findByUser($userId)
        );
        $this->permissions->requireUserAccess($request->attributes->get('auth_user') ?? [], $permissionKey, $userId, $storeIds);

        return $user;
    }
}
