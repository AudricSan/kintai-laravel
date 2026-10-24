<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Domain\Eloquent\RememberToken;
use kintai\Domain\Eloquent\User;
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
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $this->capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        $runner->run();
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
