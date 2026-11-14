<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\LoginApiUser;
use App\Actions\Api\RevokeApiToken;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\CurrentUserResource;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends ApiController
{
    /**
     * Connexion : `login` (e-mail, ou code employé avec `store_code`), `password`, `device_name`, et selon le compte
     * `code` ou `recovery_code`. Renvoie le jeton en clair, une seule fois.
     */
    public function login(LoginRequest $request, LoginApiUser $login): JsonResponse
    {
        $data = $request->validated();

        $result = $login->handle($request, $data['device_name'], (int) ($data['expires_in_days'] ?? config('kintai.api.token_max_days')));

        return response()->json([
            'token' => $result['token']->plainTextToken,
            'expires_at' => $result['expires_at']->utc()->format('Y-m-d\TH:i:s\Z'),
            'user' => (new UserResource($result['user']))->asSelf()->resolve($request),
        ]);
    }

    /**
     * Révoque le jeton de la requête.
     */
    public function logout(Request $request, RevokeApiToken $revoke): Response
    {
        $user = $this->actor($request);
        $revoke->handle($user, $user->currentAccessToken(), logout: true);

        return response()->noContent();
    }

    /**
     * Le compte, ses rôles, ses magasins et ses permissions effectives.
     */
    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($this->actor($request));
    }
}
