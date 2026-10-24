<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 13/10/2026 (M8) : supprimer un compte l'anonymise au lieu d'effacer la ligne, pour que l'historique
 * (shifts, pointages, paie) survive de la même façon sous SQLite et sous MySQL.
 */
final class UserAnonymizerTest extends TestCase
{
    private UserRepositoryInterface&Stub $users;
    private RoleAssignmentRepositoryInterface&Stub $assignments;
    private RememberTokenRepositoryInterface&Stub $remember;
    private ApiTokenRepositoryInterface&Stub $api;
    private string $avatarDir;

    protected function setUp(): void
    {
        $this->users       = $this->createStub(UserRepositoryInterface::class);
        $this->assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->remember    = $this->createStub(RememberTokenRepositoryInterface::class);
        $this->api         = $this->createStub(ApiTokenRepositoryInterface::class);
        $this->avatarDir   = sys_get_temp_dir() . '/kintai-avatars-' . bin2hex(random_bytes(4));
        mkdir($this->avatarDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->avatarDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->avatarDir);
    }

    private function anonymizer(): UserAnonymizer
    {
        return new UserAnonymizer($this->users, $this->assignments, new CredentialRevoker($this->remember, $this->api), $this->avatarDir);
    }

    public function testPersonalDataIsErasedAndTheAccountMarkedDeletedButNeverRemoved(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        file_put_contents($this->avatarDir . '/u12.webp', 'x');
        $this->users->expects($this->atLeastOnce())->method('findById')->with(12)->willReturn([
            'id' => 12, 'email' => 'aiko@example.com', 'avatar_path' => 'u12.webp', 'deleted_at' => null,
        ]);
        $this->users->expects($this->never())->method('delete');

        $saved = null;
        $this->users->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$saved): array {
            $saved = $d;
            return $d;
        });

        $this->anonymizer()->anonymize(12);

        $this->assertSame('deleted-12@kintai.local', $saved['email']);
        $this->assertSame(UserAnonymizer::DISPLAY_NAME, $saved['display_name']);
        $this->assertSame(0, $saved['is_active']);
        $this->assertNotNull($saved['deleted_at']);
        $this->assertSame('', $saved['password_hash']);
        foreach (['phone', 'mobile_phone', 'address', 'postal_code', 'birth_date', 'employee_code', 'avatar_path', 'guarantor_name'] as $field) {
            $this->assertNull($saved[$field], $field);
        }
        // language est NOT NULL : l'ancienne suppression personnelle le mettait à null (refusé par MySQL strict).
        $this->assertArrayNotHasKey('language', $saved);
        $this->assertFileDoesNotExist($this->avatarDir . '/u12.webp');
    }

    public function testRolesAndCredentialsAreRevoked(): void
    {
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->api = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 12]);
        $this->users->method('save')->willReturnArgument(0);
        $this->assignments->expects($this->atLeastOnce())->method('findByUser')->with(12)->willReturn([['id' => 3], ['id' => 4]]);

        $revoked = [];
        $this->assignments->expects($this->exactly(2))->method('revoke')->willReturnCallback(function (int $id) use (&$revoked): int {
            $revoked[] = $id;
            return 1;
        });
        $this->remember->expects($this->once())->method('deleteByUserId')->with(12);
        $this->api->expects($this->once())->method('deleteByUserId')->with(12)->willReturn(0);

        $this->anonymizer()->anonymize(12);

        $this->assertSame([3, 4], $revoked);
    }

    public function testAnExistingDeletionDateIsKept(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn(['id' => 12, 'deleted_at' => '2026-01-01 00:00:00']);
        $this->users->expects($this->once())->method('save')
            ->with($this->callback(fn(array $d): bool => $d['deleted_at'] === '2026-01-01 00:00:00'))
            ->willReturnArgument(0);

        $this->anonymizer()->anonymize(12);
    }

    public function testAnUnknownAccountIsIgnored(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->api = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->users->method('findById')->willReturn(null);
        $this->users->expects($this->never())->method('save');
        $this->api->expects($this->never())->method('deleteByUserId');

        $this->anonymizer()->anonymize(404);
    }
}
