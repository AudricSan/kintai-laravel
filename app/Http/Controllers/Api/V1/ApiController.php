<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\Authorizer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Base des contrôleurs de l'API v1 : l'utilisateur authentifié par jeton et la règle « soi-même, ou un gérant ».
 */
abstract class ApiController extends Controller
{
    protected function authorizer(): Authorizer
    {
        return app(Authorizer::class);
    }

    /**
     * Taille de page demandée (`per_page`, de 1 à 100, 20 par défaut).
     */
    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }

    protected function actor(Request $request): User
    {
        $user = $request->user('sanctum');
        assert($user instanceof User);

        return $user;
    }

    /**
     * Utilisateur visé par `/users/{user}/...` : soi-même, ou, pour un gérant, un employé qu'il peut gérer (la permission
     * demandée, sur un magasin de cet employé ; un Owner n'est géré que par un Owner). Sans ce droit, 403 avant même
     * de chercher l'employé : l'existence d'un compte n'est pas révélée.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    protected function targetUser(Request $request, int $userId, string $permission): User
    {
        $actor = $this->actor($request);

        if ($actor->id === $userId) {
            return $actor;
        }

        $authorizer = app(Authorizer::class);

        if (! $authorizer->allows($actor, $permission)) {
            throw new AuthorizationException;
        }

        $target = User::query()->findOrFail($userId);

        if (! $authorizer->canActOnUser($actor, $permission, $target)) {
            throw new AuthorizationException;
        }

        return $target;
    }
}
