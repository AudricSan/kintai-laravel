<?php

namespace App\Actions;

use App\Events\PasswordChanged;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Change le mot de passe de l'utilisateur connecté : les autres sessions sont déconnectées
 * (middleware AuthenticateSession), la session courante garde sa validité.
 */
class ChangePassword
{
    public function handle(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();

        $guard = Auth::guard('web');

        // Renouvelle le cookie « rester connecté » de la session courante (le jeton a changé avec le mot de passe).
        $guard->logoutOtherDevices($password);

        // AuthenticateSession compare l'empreinte en session au mot de passe actuel : on met la nôtre à jour.
        $user->refresh();
        session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));

        PasswordChanged::dispatch($user->id);
    }
}
