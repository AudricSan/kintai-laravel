<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Un e-mail inconnu, un compte inactif ou un magasin inexistant répondaient bien plus vite qu'un
 * vrai compte avec un mauvais mot de passe (qui paie un password_verify) : la différence de durée
 * révélait quels comptes existent. Ces chemins dépensent désormais un calcul bcrypt équivalent.
 *
 * La durée est comparée à celle d'un vrai password_verify sur la même machine (et non à un seuil
 * en millisecondes), pour ne pas dépendre de la vitesse du CPU qui exécute les tests.
 */
final class AuthServiceConstantTimeLoginTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private StoreRepositoryInterface&Stub $stores;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private AuthService $auth;
    /** Durée d'un password_verify réel au coût utilisé par l'application (12). */
    private float $verifyDuration;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);

        $this->auth = new AuthService(
            $this->users,
            $this->storeUsers,
            $this->stores,
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $secret = bin2hex(random_bytes(8));
        $hash = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 12]);
        $t = hrtime(true);
        password_verify(bin2hex(random_bytes(8)), $hash);
        $this->verifyDuration = (hrtime(true) - $t) / 1e9;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param callable(): bool $attempt */
    private function assertFailsSlowly(callable $attempt): void
    {
        $t = hrtime(true);
        $result = $attempt();
        $elapsed = (hrtime(true) - $t) / 1e9;

        $this->assertFalse($result);
        // Au moins la moitié d'un vrai password_verify : sans le calcul factice, ce serait ~0.
        $this->assertGreaterThan(
            $this->verifyDuration * 0.5,
            $elapsed,
            sprintf('Échec trop rapide (%.1f ms pour un password_verify réel de %.1f ms)', $elapsed * 1000, $this->verifyDuration * 1000)
        );
    }

    public function testUnknownEmailCostsAsMuchAsAWrongPassword(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $this->assertFailsSlowly(fn() => $this->auth->attempt('nobody@example.test', bin2hex(random_bytes(6))));
    }

    public function testInactiveAccountCostsAsMuchAsAWrongPassword(): void
    {
        $this->users->method('findByEmail')->willReturn(['id' => 3, 'is_active' => 0, 'deleted_at' => null, 'password_hash' => 'not-a-real-hash']);

        $this->assertFailsSlowly(fn() => $this->auth->attempt('gone@example.test', bin2hex(random_bytes(6))));
    }

    public function testUnknownStoreCodeCostsAsMuchAsAWrongPassword(): void
    {
        $this->stores->method('findByCode')->willReturn(null);

        $this->assertFailsSlowly(fn() => $this->auth->attemptByCode('E001', 'NOPE', bin2hex(random_bytes(6))));
    }

    public function testUnknownEmployeeCodeCostsAsMuchAsAWrongPassword(): void
    {
        $this->stores->method('findByCode')->willReturn(['id' => 1]);
        $this->users->method('findByEmployeeCode')->willReturn(null);

        $this->assertFailsSlowly(fn() => $this->auth->attemptByCode('E404', 'TOKYO', bin2hex(random_bytes(6))));
    }

    public function testNonMemberCostsAsMuchAsAWrongPassword(): void
    {
        $this->stores->method('findByCode')->willReturn(['id' => 1]);
        $this->users->method('findByEmployeeCode')->willReturn(['id' => 5, 'is_active' => 1, 'deleted_at' => null, 'password_hash' => 'not-a-real-hash']);
        $this->storeUsers->method('findMembership')->willReturn(null);

        $this->assertFailsSlowly(fn() => $this->auth->attemptByCode('E005', 'TOKYO', bin2hex(random_bytes(6))));
    }

    public function testValidLoginStillWorks(): void
    {
        $secret = bin2hex(random_bytes(8));
        $this->users->method('findByEmail')->willReturn([
            'id' => 9, 'is_active' => 1, 'deleted_at' => null,
            'password_hash' => password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $this->assertTrue($this->auth->attempt('ok@example.test', $secret));
    }
}
