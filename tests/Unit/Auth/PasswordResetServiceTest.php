<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\PasswordResetRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\PasswordResetService;

final class PasswordResetServiceTest extends TestCase
{
    private PasswordResetRepositoryInterface&Stub $resets;
    private UserRepositoryInterface&Stub $users;
    private ?PasswordResetService $service = null;

    protected function setUp(): void
    {
        $this->resets = $this->createStub(PasswordResetRepositoryInterface::class);
        $this->users  = $this->createStub(UserRepositoryInterface::class);

        // MailerService est final — on instancie la vraie classe avec driver natif.
        // mail() retourne false en CLI, ce qui est attendu en test.
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): PasswordResetService
    {
        if ($this->service === null) {
            $mailer = new MailerService([
                'driver' => 'native',
                'from'   => ['address' => 'test@kintai.test', 'name' => 'Kintai'],
            ]);
            $this->service = new PasswordResetService($this->resets, $this->users, $mailer);
        }

        return $this->service;
    }

    private function activeUser(): array
    {
        return [
            'id'            => 1,
            'email'         => 'user@test.com',
            'display_name'  => 'Jean Dupont',
            'first_name'    => 'Jean',
            'last_name'     => 'Dupont',
            'password_hash' => password_hash('old', PASSWORD_BCRYPT),
            'is_active'     => 1,
            'deleted_at'    => null,
        ];
    }

    private function validRecord(): array
    {
        return [
            'id'         => 1,
            'email'      => 'user@test.com',
            'token'      => 'abc123',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];
    }

    // ── sendResetLink ─────────────────────────────────────────────────────────

    public function testSendResetLinkReturnsTrueForUnknownEmail(): void
    {
        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        $this->users->method('findByEmail')->willReturn(null);
        $this->resets->expects($this->never())->method('create');

        $this->assertTrue($this->service()->sendResetLink('unknown@test.com', 'https://example.com'));
    }

    public function testSendResetLinkReturnsTrueForInactiveUser(): void
    {
        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        $user = array_merge($this->activeUser(), ['is_active' => 0]);
        $this->users->method('findByEmail')->willReturn($user);
        $this->resets->expects($this->never())->method('create');

        $this->assertTrue($this->service()->sendResetLink('user@test.com', 'https://example.com'));
    }

    public function testSendResetLinkCreatesTokenForActiveUser(): void
    {
        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        $this->users->method('findByEmail')->willReturn($this->activeUser());
        $this->resets->expects($this->once())->method('create');

        // mail() retourne false en CLI — on vérifie seulement que le token est créé
        $this->service()->sendResetLink('user@test.com', 'https://example.com');
    }

    public function testSendResetLinkReturnsTrueForSoftDeletedUser(): void
    {
        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        $deleted = array_merge($this->activeUser(), ['deleted_at' => '2025-01-01 00:00:00']);
        $this->users->method('findByEmail')->willReturn($deleted);
        $this->resets->expects($this->never())->method('create');

        $this->assertTrue($this->service()->sendResetLink('user@test.com', 'https://example.com'));
    }

    public function testTheResetLinkIsAbsolute(): void
    {
        // Le lien part par e-mail : un chemin seul (ancien comportement, « /reset-password/… ») est inutilisable.
        $this->assertSame(
            'https://kintai.example.com/sub/reset-password/abc123',
            PasswordResetService::resetLink('https://kintai.example.com/sub/', 'abc123'),
        );
    }

    public function testNoLinkCanBeBuiltFromAPathOnly(): void
    {
        $this->assertNull(PasswordResetService::resetLink('/Kintai', 'abc123'));
        $this->assertNull(PasswordResetService::resetLink('', 'abc123'));
    }

    public function testWithoutAPublicUrlNothingIsSentAndNoTokenIsCreated(): void
    {
        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        // Sans URL publique, mieux vaut ne rien envoyer (et le journaliser) qu'un lien cassé.
        $this->users->method('findByEmail')->willReturn($this->activeUser());
        $this->resets->expects($this->never())->method('create');

        $this->assertFalse($this->service()->sendResetLink('user@test.com', ''));
        $this->assertFalse($this->service()->sendResetLink('user@test.com', '/Kintai'));
    }

    // ── findValidToken ────────────────────────────────────────────────────────

    public function testFindValidTokenReturnsNullForUnknownToken(): void
    {
        $this->resets->method('findByToken')->willReturn(null);

        $this->assertNull($this->service()->findValidToken('badtoken'));
    }

    public function testFindValidTokenReturnsNullForExpiredToken(): void
    {
        $expired = array_merge($this->validRecord(), [
            'expires_at' => date('Y-m-d H:i:s', time() - 10),
        ]);
        $this->resets->method('findByToken')->willReturn($expired);

        $this->assertNull($this->service()->findValidToken('abc123'));
    }

    public function testFindValidTokenReturnsRecordForValidToken(): void
    {
        $this->resets->method('findByToken')->willReturn($this->validRecord());

        $this->assertNotNull($this->service()->findValidToken('abc123'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetReturnsFalseForInvalidToken(): void
    {
        $this->resets->method('findByToken')->willReturn(null);

        $this->assertFalse($this->service()->reset('badtoken', 'newpassword'));
    }

    public function testResetUpdatesPasswordAndDeletesToken(): void
    {
        $this->resets = $this->createMock(PasswordResetRepositoryInterface::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->resets->method('findByToken')->willReturn($this->validRecord());
        $this->users->method('findByEmail')->willReturn($this->activeUser());

        $this->users->expects($this->once())->method('save')
            ->with($this->callback(fn(array $data) => password_verify('newpassword', $data['password_hash'])));

        $this->resets->expects($this->once())->method('deleteByEmail')
            ->with('user@test.com');

        $this->assertTrue($this->service()->reset('abc123', 'newpassword'));
    }

    public function testResetReturnsFalseIfUserDisabledAfterTokenCreation(): void
    {
        $this->resets->method('findByToken')->willReturn($this->validRecord());
        $this->users->method('findByEmail')->willReturn(
            array_merge($this->activeUser(), ['is_active' => 0])
        );

        $this->assertFalse($this->service()->reset('abc123', 'newpassword'));
    }
}
