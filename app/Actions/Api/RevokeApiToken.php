<?php

namespace App\Actions\Api;

use App\Activity\ActivityLogger;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Révoque un jeton d'accès de l'API et le journalise : `auth.api_logout` pour le jeton de la requête (déconnexion),
 * `auth.api_token_revoked` pour un autre jeton de l'utilisateur.
 */
class RevokeApiToken
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(User $user, PersonalAccessToken $token, bool $logout = false): void
    {
        $device = $token->name;
        $token->delete();

        $this->activity->record($logout ? 'auth.api_logout' : 'auth.api_token_revoked', $user, null, ['name' => $user->name, 'device' => $device], $user);
    }
}
