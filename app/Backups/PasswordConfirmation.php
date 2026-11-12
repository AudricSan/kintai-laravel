<?php

namespace App\Backups;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Revérifie le mot de passe de l'Owner avant une opération destructrice (restauration, réinitialisation). Les essais
 * ratés sont limités (5 par minute et par compte) pour qu'une session ouverte ne serve pas à deviner le mot de passe.
 */
class PasswordConfirmation
{
    /**
     * @throws ValidationException
     */
    public function verify(User $user, string $password, string $field = 'password'): void
    {
        $key = 'confirm-password:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([$field => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if ($password === '' || ! Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([$field => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
    }
}
