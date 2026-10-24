<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;

/**
 * Révoque les identifiants persistants d'un utilisateur : cookies « rester connecté »
 * (30 jours) et jetons d'API. À appeler dès que le mot de passe change (par lui-même,
 * par réinitialisation ou par un admin) ou que le compte est désactivé/supprimé, sinon un
 * cookie ou un jeton volé survivrait au changement de mot de passe censé le neutraliser.
 *
 * Les sessions PHP déjà ouvertes ne sont pas énumérables côté serveur : elles sont
 * invalidées par AuthService (compte inactif, ou empreinte du mot de passe qui a changé).
 */
final class CredentialRevoker
{
    public function __construct(
        private readonly RememberTokenRepositoryInterface $rememberTokens,
        private readonly ApiTokenRepositoryInterface $apiTokens,
    ) {}

    public function revokeAllFor(int $userId): void
    {
        $this->rememberTokens->deleteByUserId($userId);
        $this->apiTokens->deleteByUserId($userId);
    }
}
