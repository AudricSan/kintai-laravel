<?php

namespace App\Actions\Push;

use App\Models\DevicePushToken;
use App\Models\User;

/**
 * Supprime le jeton FCM d'un appareil (déconnexion de l'application mobile). Ne touche qu'aux jetons de l'utilisateur.
 */
class RemoveDeviceToken
{
    /**
     * @return bool Vrai si un jeton de cet utilisateur a été supprimé.
     */
    public function handle(User $user, string $token): bool
    {
        return DevicePushToken::query()
            ->where('user_id', $user->id)
            ->where('token', trim($token))
            ->delete() > 0;
    }
}
