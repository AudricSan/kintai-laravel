<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

/**
 * Hachage unique des mots de passe (bcrypt, coût fixe).
 *
 * Avant, le changement depuis le profil utilisait PASSWORD_DEFAULT (coût 10) et le reste bcrypt coût 12. Outre
 * l'incohérence, AuthService dépense un calcul au coût 12 pour masquer le temps de réponse d'un compte inconnu :
 * un compte haché au coût 10 répondait donc plus vite. Tous les hachages passent désormais par ici.
 */
final class PasswordHasher
{
    public const COST = 12;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }
}
