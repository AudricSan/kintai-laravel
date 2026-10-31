<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Règle unique de longueur d'un mot de passe choisi par l'utilisateur (changement depuis le profil,
 * réinitialisation par e-mail). Avant, chaque parcours avait son propre seuil (8 à la réinitialisation,
 * 4 au profil) : changer son mot de passe depuis le profil permettait de choisir plus faible que ce que
 * la réinitialisation exigeait.
 *
 * Un compte créé ou réinitialisé par un administrateur est marqué « à changer » (users.must_change_password), comme
 * un mot de passe trop court saisi à la connexion : le web le renvoie vers le profil tant que ce n'est pas fait
 * (PasswordReminderMiddleware, AuthService::mustChangePassword()), et l'API refuse de lui délivrer un jeton.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * Mot de passe temporaire aléatoire attribué à un compte créé ou réinitialisé sans mot de passe choisi. Montré une
     * seule fois à l'administrateur ; le compte doit le changer avant d'utiliser l'application (users.must_change_password).
     */
    public static function temporary(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public static function isLongEnough(string $password): bool
    {
        return mb_strlen($password) >= self::MIN_LENGTH;
    }
}
