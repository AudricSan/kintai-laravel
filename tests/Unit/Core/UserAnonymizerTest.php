<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Models\ApiToken;
use App\Models\RoleAssignment;
use App\Models\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 13/10/2026 (M8) : supprimer un compte l'anonymise au lieu d'effacer la ligne, pour que l'historique
 * (shifts, pointages, paie) survive de la même façon sous SQLite et sous MySQL.
 */
final class UserAnonymizerTest extends TestCase
{
    use \Tests\Support\ApiTokenExpectations;
    private string $avatarDir;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
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
        return new UserAnonymizer(new CredentialRevoker(), $this->avatarDir);
    }

    public function testPersonalDataIsErasedAndTheAccountMarkedDeletedButNeverRemoved(): void
    {
        file_put_contents($this->avatarDir . '/u12.webp', 'x');
        kintai_test_users(findById: fn(int $id) => $id === 12 ? [
            'id' => 12, 'email' => 'aiko@example.com', 'avatar_path' => 'u12.webp', 'deleted_at' => null,
        ] : null);
        $saved = null;
        $this->anonymizer()->anonymize(12);

        $saved = \App\Models\User::findById(12);
        $this->assertSame('deleted-12@kintai.local', $saved['email']);
        $this->assertSame(UserAnonymizer::DISPLAY_NAME, $saved['display_name']);
        $this->assertSame(0, $saved['is_active']);
        $this->assertNotNull($saved['deleted_at']);
        $this->assertSame('', $saved['password_hash']);
        foreach (['phone', 'mobile_phone', 'address', 'postal_code', 'birth_date', 'employee_code', 'avatar_path', 'guarantor_name'] as $field) {
            $this->assertNull($saved[$field], $field);
        }
        // language est NOT NULL : l'ancienne suppression personnelle le mettait à null (refusé par MySQL strict).
        $this->assertNotNull($saved['language']);
        $this->assertFileDoesNotExist($this->avatarDir . '/u12.webp');
    }

    public function testRolesAndCredentialsAreRevoked(): void
    {
        kintai_test_users(findById: fn() => ['id' => 12]);
        kintai_test_assignments(findByUser: fn(int $id) => $id === 12 ? [
            ['id' => 3, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1],
            ['id' => 4, 'role_id' => 3, 'scope_type' => 'global'],
        ] : []);
        kintai_test_remember_token(12);
        $this->expectApiTokensRevoked(12);

        $this->anonymizer()->anonymize(12);

        $this->assertSame(0, \App\Models\RoleAssignment::query()->where('user_id', 12)->count(), 'affectations révoquées');
        $this->assertNull(\App\Models\User::query()->find(12)?->remember_token, 'jeton « rester connecté » révoqué');
    }

    public function testAnExistingDeletionDateIsKept(): void
    {
        kintai_test_users(findById: fn() => ['id' => 12, 'deleted_at' => '2026-01-01 00:00:00']);
        $this->anonymizer()->anonymize(12);

        $this->assertSame('2026-01-01 00:00:00', \App\Models\User::findById(12)['deleted_at']);
    }

    public function testAnUnknownAccountIsIgnored(): void
    {
        kintai_test_users(findById: fn() => null);
        $this->expectApiTokensKept(1);

        $this->anonymizer()->anonymize(404);
    }
}
