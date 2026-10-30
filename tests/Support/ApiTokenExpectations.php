<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ApiToken;

/**
 * Jetons d'API dans la table api_tokens pour les tests de révocation des identifiants : un jeton est préparé pour
 * l'utilisateur avant l'action, puis on vérifie à la fin qu'il a été révoqué (ou conservé).
 */
trait ApiTokenExpectations
{
    /** @var list<int> */
    private array $revokedUsers = [];
    /** @var list<int> */
    private array $keptUsers = [];

    private function seedApiToken(int $userId): void
    {
        kintai_test_user($userId);
        ApiToken::put(['user_id' => $userId, 'token' => hash('sha256', 'jeton-' . $userId . '-' . uniqid('', true)), 'name' => 'test']);
    }

    /** Un jeton de cet utilisateur existe, et doit avoir disparu à la fin du test. */
    private function expectApiTokensRevoked(int $userId): void
    {
        $this->seedApiToken($userId);
        $this->revokedUsers[] = $userId;
    }

    /** Un jeton de cet utilisateur existe, et doit être encore là à la fin du test. */
    private function expectApiTokensKept(int $userId): void
    {
        $this->seedApiToken($userId);
        $this->keptUsers[] = $userId;
    }

    protected function assertPostConditions(): void
    {
        foreach ($this->revokedUsers as $userId) {
            $this->assertSame([], ApiToken::findByUserId($userId), "jetons d'API révoqués ($userId)");
        }
        foreach ($this->keptUsers as $userId) {
            $this->assertNotSame([], ApiToken::findByUserId($userId), "jetons d'API conservés ($userId)");
        }
    }
}
