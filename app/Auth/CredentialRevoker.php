<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\ApiToken;
use App\Repositories\UserRepositoryInterface;

/**
 * Révoque les identifiants persistants d'un utilisateur : cookie « rester connecté » (jeton users.remember_token du
 * garde de Laravel) et jetons d'API. À appeler dès que le mot de passe change (par lui-même, par réinitialisation ou
 * par un admin) ou que le compte est désactivé/supprimé, sinon un cookie ou un jeton volé survivrait au changement de
 * mot de passe censé le neutraliser.
 *
 * Les sessions déjà ouvertes ne sont pas énumérables côté serveur : elles sont invalidées par AuthenticateSession
 * (empreinte du mot de passe) et par AuthService (compte inactif).
 */
final class CredentialRevoker
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function revokeAllFor(int $userId): void
    {
        $this->users->revokeRememberToken($userId);
        ApiToken::deleteByUserId($userId);
    }
}