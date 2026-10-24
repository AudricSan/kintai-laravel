<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\PasswordResetRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\PasswordResetService;
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
    private PasswordResetRepositoryInterface&Stub $resets;
    private UserRepositoryInterface&Stub $users;
    private RememberTokenRepositoryInterface&Stub $remember;
    private ApiTokenRepositoryInterface&Stub $api;
    private ?PasswordResetService $service = null;

    protected function setUp(): void
    {
        $this->resets   = $this->createStub(PasswordResetRepositoryInterface::class);
        $this->users    = $this->createStub(UserRepositoryInterface::class);
        $this->remember = $this->createStub(RememberTokenRepositoryInterface::class);
        $this->api      = $this->createStub(ApiTokenRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): PasswordResetService
    {
        if ($this->service === null) {
            $mailer = new MailerService([
                'driver' => 'native',
                'from'   => ['address' => 'test@kintai.test', 'name' => 'Kintai'],
            ]);
            $this->service = new PasswordResetService(
                $this->resets,
                $this->users,
                $mailer,
                new CredentialRevoker($this->remember, $this->api),
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
        $this->remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->api = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->resets->method('findByToken')->willReturn($this->validRecord());
        $this->users->method('findByEmail')->willReturn($this->user());

        $this->remember->expects($this->once())->method('deleteByUserId')->with(7);
        $this->api->expects($this->once())->method('deleteByUserId')->with(7)->willReturn(0);

        $this->assertTrue($this->service()->reset('abc', self::newPassword()));
    }

    public function testFailedResetRevokesNothing(): void
    {
        $this->remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->api = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->resets->method('findByToken')->willReturn(null);

        $this->remember->expects($this->never())->method('deleteByUserId');
        $this->api->expects($this->never())->method('deleteByUserId');

        $this->assertFalse($this->service()->reset('bad', self::newPassword()));
    }

    public function testResetForADeactivatedUserRevokesNothing(): void
    {
        $this->remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->resets->method('findByToken')->willReturn($this->validRecord());
        $this->users->method('findByEmail')->willReturn($this->user(0));

        $this->remember->expects($this->never())->method('deleteByUserId');

        $this->assertFalse($this->service()->reset('abc', self::newPassword()));
    }
}
