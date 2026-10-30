<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Mail\MailerService;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PasswordResetService;

final class PasswordResetServiceTest extends TestCase
{
    private ?int $expectedTokens = null;
    private ?PasswordResetService $service = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();

        // MailerService est final — on instancie la vraie classe avec driver natif.
        // mail() retourne false en CLI, ce qui est attendu en test.
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): PasswordResetService
    {
        if ($this->service === null) {
            $mailer = new MailerService([
                'default' => 'sendmail',
                'from'   => ['address' => 'test@kintai.test', 'name' => 'Kintai'],
            ]);
            $this->service = new PasswordResetService($mailer);
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
        kintai_test_users(findByEmail: fn() => null);
        $this->expectedTokens = 0;

        $this->assertTrue($this->service()->sendResetLink('unknown@test.com', 'https://example.com'));
    }

    public function testSendResetLinkReturnsTrueForInactiveUser(): void
    {
        $user = array_merge($this->activeUser(), ['is_active' => 0]);
        kintai_test_users(findByEmail: fn() => $user);
        $this->expectedTokens = 0;

        $this->assertTrue($this->service()->sendResetLink('user@test.com', 'https://example.com'));
    }

    public function testSendResetLinkCreatesTokenForActiveUser(): void
    {
        kintai_test_users(findByEmail: fn() => $this->activeUser());
        $this->expectedTokens = 1;

        // mail() retourne false en CLI — on vérifie seulement que le token est créé
        $this->service()->sendResetLink('user@test.com', 'https://example.com');
    }

    public function testSendResetLinkReturnsTrueForSoftDeletedUser(): void
    {
        $deleted = array_merge($this->activeUser(), ['deleted_at' => '2025-01-01 00:00:00']);
        kintai_test_users(findByEmail: fn() => $deleted);
        $this->expectedTokens = 0;

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
        // Sans URL publique, mieux vaut ne rien envoyer (et le journaliser) qu'un lien cassé.
        kintai_test_users(findByEmail: fn() => $this->activeUser());
        $this->expectedTokens = 0;

        $this->assertFalse($this->service()->sendResetLink('user@test.com', ''));
        $this->assertFalse($this->service()->sendResetLink('user@test.com', '/Kintai'));
    }

    // ── findValidToken ────────────────────────────────────────────────────────

    public function testFindValidTokenReturnsNullForUnknownToken(): void
    {

        $this->assertNull($this->service()->findValidToken('badtoken'));
    }

    public function testFindValidTokenReturnsNullForExpiredToken(): void
    {
        $expired = array_merge($this->validRecord(), [
            'expires_at' => date('Y-m-d H:i:s', time() - 10),
        ]);
        $this->seedToken($expired);

        $this->assertNull($this->service()->findValidToken('abc123'));
    }

    public function testFindValidTokenReturnsRecordForValidToken(): void
    {
        $this->seedToken($this->validRecord());

        $this->assertNotNull($this->service()->findValidToken('abc123'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetReturnsFalseForInvalidToken(): void
    {

        $this->assertFalse($this->service()->reset('badtoken', 'newpassword'));
    }

    public function testResetUpdatesPasswordAndDeletesToken(): void
    {
        $this->seedToken($this->validRecord());
        kintai_test_users(findByEmail: fn() => $this->activeUser());
        $this->expectedTokens = 0;

        $this->assertTrue($this->service()->reset('abc123', 'newpassword'));
    }

    public function testResetReturnsFalseIfUserDisabledAfterTokenCreation(): void
    {
        $this->seedToken($this->validRecord());
        kintai_test_users(findByEmail: fn() => array_merge($this->activeUser(), ['is_active' => 0]));
        $this->assertFalse($this->service()->reset('abc123', 'newpassword'));
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
