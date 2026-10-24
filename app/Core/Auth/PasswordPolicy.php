<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

/**
 * Règle unique de longueur d'un mot de passe choisi par l'utilisateur (changement depuis le profil,
 * réinitialisation par e-mail). Avant, chaque parcours avait son propre seuil (8 à la réinitialisation,
 * 4 au profil) : changer son mot de passe depuis le profil permettait de choisir plus faible que ce que
 * la réinitialisation exigeait.
 *
 * Le mot de passe par défaut « 0000 » attribué à la création d'un compte ne respecte pas MIN_LENGTH. Tant
 * qu'un utilisateur le garde (ou garde un mot de passe trop court), le web le lui rappelle par un bandeau et
 * une fenêtre à chaque connexion (PasswordReminderMiddleware, AuthService::mustChangePassword()), et l'API
 * refuse de lui délivrer un jeton.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** Mot de passe attribué à un compte créé ou réinitialisé par un admin. */
    public const DEFAULT_PASSWORD = '0000';

    public static function isLongEnough(string $password): bool
    {
        return mb_strlen($password) >= self::MIN_LENGTH;
    }
}
