<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\RememberToken;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * remember_tokens ("rester connecté" 30 jours, voir AuthService) a été droppée le
 * 2026-08-06 faute d'implémentation, puis recréée le 2026-08-07 avec une vraie
 * implémentation — ce test verrouille qu'une installation fraîche crée bien la table,
 * avec le schéma sélecteur/validateur attendu par DatabaseRememberTokenRepository.
 */
final class RememberTokensTableTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = kintai_test_database();

        kintai_test_migrate();
    }

    public function testFreshInstallCreatesTableWithExpectedColumns(): void
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        $this->assertTrue($schema->hasTable('remember_tokens'));
        $this->assertTrue($schema->hasColumns('remember_tokens', [
            'id', 'user_id', 'selector', 'validator_hash', 'expires_at', 'created_at',
        ]));
    }

    public function testCanStoreAndRetrieveATokenBySelector(): void
    {
        $user = User::create([
            'email' => 'user@test.com', 'password_hash' => 'x',
            'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'is_active' => 1,
        ]);

        RememberToken::create([
            'user_id'        => $user->id,
            'selector'       => 'abc123',
            'validator_hash' => hash('sha256', 'secret'),
            'expires_at'     => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $found = RememberToken::where('selector', 'abc123')->first();
        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->user_id);
    }
}
