<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\CredentialRevoker;
use App\Mail\MailerService;
use App\Models\ApiToken;
use App\Models\PasswordResetToken;
use App\Repositories\UserRepositoryInterface;
use App\Services\PasswordResetService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Réinitialiser un mot de passe (typiquement après une compromission) doit aussi révoquer les
 * cookies « rester connecté » et les jetons d'API : sinon un cookie volé survit 30 jours au
 * changement censé le neutraliser.
 */
final class PasswordResetRevokesCredentialsTest extends TestCase
{
    use \Tests\Support\ApiTokenExpectations;

    private ?int $expectedTokens = null;
    private UserRepositoryInterface&Stub $users;
    private UserRepositoryInterface&Stub $remember;
    private ?PasswordResetService $service = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $this->users    = $this->createStub(UserRepositoryInterface::class);
        $this->remember = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): PasswordResetService
    {
        if ($this->service === null) {
            $mailer = new MailerService([
                'default' => 'sendmail',
                'from'   => ['address' => 'test@kintai.test', 'name' => 'Kintai'],
            ]);
            $this->service = new PasswordResetService(
                $this->users,
                $mailer,
                new CredentialRevoker($this->remember),
            );
        }

        return $this->service;
    }

    private static function newPassword(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function validRecord(): array
    {
        return ['id' => 1, 'email' => 'user@test.com', 'token' => 'abc', 'expires_at' => date('Y-m-d H:i:s', time() + 3600)];
    }

    private function user(int $active = 1): array
    {
        return ['id' => 7, 'email' => 'user@test.com', 'password_hash' => 'not-a-real-hash', 'is_active' => $active, 'deleted_at' => null];
    }

    public function testSuccessfulResetRevokesRememberCookiesAndApiTokens(): void
    {
        $this->remember = $this->createMock(UserRepositoryInterface::class);
        $this->seedToken($this->validRecord());
        $this->users->method('findByEmail')->willReturn($this->user());

        $this->remember->expects($this->once())->method('revokeRememberToken')->with(7);
        $this->expectApiTokensRevoked(7);

        $this->assertTrue($this->service()->reset('abc', self::newPassword()));
    }

    public function testFailedResetRevokesNothing(): void
    {
        $this->remember = $this->createMock(UserRepositoryInterface::class);

        $this->remember->expects($this->never())->method('revokeRememberToken');
        $this->expectApiTokensKept(1);

        $this->assertFalse($this->service()->reset('bad', self::newPassword()));
    }

    public function testResetForADeactivatedUserRevokesNothing(): void
    {
        $this->remember = $this->createMock(UserRepositoryInterface::class);
        $this->seedToken($this->validRecord());
        $this->users->method('findByEmail')->willReturn($this->user(0));

        $this->remember->expects($this->never())->method('revokeRememberToken');

        $this->assertFalse($this->service()->reset('abc', self::newPassword()));
    }

    /** Enregistre un jeton de réinitialisation tel que le service le stocke (empreinte du jeton). */
    private function seedToken(array $record): void
    {
        PasswordResetToken::create([
            'email'      => $record['email'],
            'token'      => hash_token($record['token']),
            'expires_at' => $record['expires_at'],
        ]);
    }

    protected function assertPostConditions(): void
    {
        if ($this->expectedTokens !== null) {
            $this->assertSame($this->expectedTokens, PasswordResetToken::count(), 'jetons de réinitialisation enregistrés');
        }
    }
}
