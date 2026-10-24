<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * La recherche d'un jeton de réinitialisation se fait par sa valeur hachée : une installation neuve n'avait pas
 * d'index dessus (les anciennes instances en ont un, hérité d'un ancien schéma). La migration l'ajoute, sans
 * doublon là où il existe déjà.
 */
final class PasswordResetTokenIndexTest extends TestCase
{
    private const MIGRATION = '2026_09_30_000000_add_token_index_to_password_reset_tokens_table.php';

    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    private function migrateAll(): void
    {
        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $this->capsule, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            $p = new \ReflectionProperty($runner, $prop);
            $p->setAccessible(true);
            $p->setValue($runner, $value);
        }
        $runner->run();
    }

    private function migration(): object
    {
        $capsule = $this->capsule;
        $file = dirname(__DIR__, 2) . '/database/migrations/php/' . self::MIGRATION;

        // Les fichiers de migration utilisent $this->capsule : on les inclut depuis un objet qui l'expose.
        return (function () use ($file) {
            return require $file;
        })->call(new class ($capsule) {
            public function __construct(public Capsule $capsule)
            {
            }
        });
    }

    /** @return list<list<string>> colonnes de chaque index de la table */
    private function indexedColumns(): array
    {
        return array_map(
            static fn(array $i): array => $i['columns'],
            $this->capsule->getConnection()->getSchemaBuilder()->getIndexes('password_reset_tokens')
        );
    }

    public function testAFreshInstallHasAnIndexOnTheToken(): void
    {
        $this->migrateAll();

        $this->assertContains(['token'], $this->indexedColumns());
    }

    public function testRunningTheMigrationAgainDoesNotDuplicateTheIndex(): void
    {
        $this->migrateAll();
        $this->migration()->up();

        $tokenIndexes = array_filter($this->indexedColumns(), static fn(array $c): bool => $c === ['token']);
        $this->assertCount(1, $tokenIndexes);
    }

    public function testAnExistingTokenIndexIsReusedInsteadOfDuplicated(): void
    {
        // Cas des anciennes instances : un index unique sur token existe déjà.
        $this->capsule->getConnection()->getSchemaBuilder()->create('password_reset_tokens', function ($t) {
            $t->string('email')->primary();
            $t->string('token')->unique();
        });

        $this->migration()->up();

        $tokenIndexes = array_filter($this->indexedColumns(), static fn(array $c): bool => $c === ['token']);
        $this->assertCount(1, $tokenIndexes);
    }

    public function testDownRemovesTheIndex(): void
    {
        $this->migrateAll();

        $this->migration()->down();

        $this->assertNotContains(['token'], $this->indexedColumns());
    }
}
