<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\RevokeApiToken;
use App\Http\Resources\V1\AccessTokenResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Jetons d'accès de l'utilisateur authentifié (les siens uniquement, jamais leur valeur).
 */
class AccessTokenController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AccessTokenResource::collection($this->actor($request)->tokens()->orderByDesc('id')->get());
    }

    public function destroy(Request $request, int $token, RevokeApiToken $revoke): Response
    {
        $user = $this->actor($request);
        $revoke->handle($user, $user->tokens()->whereKey($token)->firstOrFail());

        return response()->noContent();
    }
}
